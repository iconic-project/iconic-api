<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactType;
use App\Enums\PreferredChannel;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Services\Config\CurrentConfig;
use App\Support\Contacts\PhoneNumber;
use App\Support\Crm\ContactDerived;
use App\Support\Templates\UnsubscribeLink;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $country
 * @property PreferredChannel $preferred_channel
 * @property ContactType $type
 * @property string $language
 * @property string|null $phone_e164
 * @property array<string, mixed>|null $first_touch
 * @property array<string, mixed>|null $last_touch
 * @property string|null $unsubscribe_token
 * @property Carbon|null $engine_identified_at
 * @property int|null $merged_into_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact|null $mergedInto
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'country',
    'preferred_channel',
    'type',
    'language',
    'phone_e164',
    'first_touch',
    'last_touch',
    'engine_identified_at',
    'merged_into_id',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    public ?int $resolvedFromAliasId = null;

    public ?int $resolvedMergeId = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_channel' => PreferredChannel::class,
            'type' => ContactType::class,
            'first_touch' => 'array',
            'last_touch' => 'array',
            'engine_identified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Contact $contact): void {
            $contact->ensureUnsubscribeToken();
        });
    }

    public function ensureUnsubscribeToken(): ?string
    {
        if (! self::unsubscribeTokenColumnExists()) {
            return null;
        }

        $token = UnsubscribeLink::token($this);

        if ($this->unsubscribe_token === $token) {
            return $token;
        }

        DB::table('contacts')->where('id', $this->id)->update([
            'unsubscribe_token' => $token,
        ]);
        $this->unsubscribe_token = $token;

        return $token;
    }

    /**
     * Information schema, not the schema builder: that cache would stay false
     * for the rest of a migrate that added the column later in the same process.
     */
    private static function unsubscribeTokenColumnExists(): bool
    {
        $row = DB::selectOne(
            "select count(*) as present from information_schema.columns where table_schema = database() and table_name = 'contacts' and column_name = 'unsubscribe_token'",
        );

        return (int) ($row->present ?? 0) > 0;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = self::normalizeEmail($value);
    }

    public static function normalizePhone(?string $phone, ?string $country): ?string
    {
        return PhoneNumber::toE164($phone, $country);
    }

    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $folded = mb_strtolower(trim($name));

        if ($folded === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $folded);

        return is_string($collapsed) ? $collapsed : null;
    }

    /**
     * @param  Builder<Contact>  $query
     */
    public function scopeNotMerged(Builder $query): void
    {
        $query->whereNull('contacts.merged_into_id');
    }

    public function currentSurvivor(): Contact
    {
        $seen = [];
        $current = $this;

        while ($current->merged_into_id !== null) {
            if (isset($seen[$current->id])) {
                break;
            }

            $seen[$current->id] = true;
            $next = self::query()->find($current->merged_into_id);

            if (! $next instanceof self) {
                break;
            }

            $current = $next;
        }

        return $current;
    }

    public static function resolveIdentity(int $id): ?Contact
    {
        $resolved = (new self)->resolveRouteBinding($id);

        return $resolved instanceof self ? $resolved : null;
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $contact = $this->where($field ?? $this->getRouteKeyName(), $value)->first();

        if (! $contact instanceof self) {
            return null;
        }

        $alias = ContactAlias::query()
            ->where('alias_id', $contact->id)
            ->whereHas('merge', fn (Builder $query) => $query->whereNull('undone_at'))
            ->first();

        if (! $alias instanceof ContactAlias) {
            return $contact;
        }

        $survivor = $alias->survivor->currentSurvivor();
        $survivor->resolvedFromAliasId = $contact->id;
        $survivor->resolvedMergeId = $alias->merge_id;

        return $survivor;
    }

    /**
     * @param  Builder<Contact>  $query
     */
    public function scopeWithDerived(Builder $query): void
    {
        $crm = app(CurrentConfig::class)->businessRules()->crm;

        if ($query->getQuery()->columns === null) {
            $query->select('contacts.*');
        }

        $query
            ->selectRaw('('.ContactDerived::lifetimeValueSql().') as lifetime_value')
            ->selectRaw('('.ContactDerived::lastStayCheckOutSql().') as last_stay_check_out')
            ->selectRaw('('.ContactDerived::nextStayCheckInSql().') as next_stay_check_in')
            ->selectRaw('('.ContactDerived::staysCountSql().') as stays_count')
            ->selectRaw('('.ContactDerived::nightsCountSql().') as nights_count')
            ->selectRaw('('.ContactDerived::lastRoomTypeSql().') as last_room_type')
            ->selectRaw('('.ContactDerived::segmentSql($crm).') as segment')
            ->selectRaw('('.ContactDerived::lifecycleSql().') as lifecycle')
            ->selectRaw('('.ContactDerived::marketingConsentSql().') as marketing_consent')
            ->selectRaw('('.ContactDerived::npsSql().') as nps')
            ->selectRaw('('.ContactDerived::firstBookingColumnSql('main_channel').') as first_main_channel')
            ->selectRaw('('.ContactDerived::firstBookingColumnSql('channel_of_origin').') as first_channel_of_origin');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    public function historyLabel(): string
    {
        return $this->name;
    }
}
