<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\CheckoutPath;
use App\Enums\CheckoutSessionStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Iso;
use Carbon\CarbonImmutable;
use Database\Factories\CheckoutSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $token_hash
 * @property CarbonImmutable|null $check_in
 * @property CarbonImmutable|null $check_out
 * @property list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string, room_id: int}>|null $rooms
 * @property array{first_name: string, last_name: string, email: string, phone: string|null, preferred_channel: string, marketing: bool, declarations: list<string>, travel_advisor: bool, notes: string|null}|null $guest
 * @property CheckoutSessionStatus $status
 * @property Carbon $expires_at
 * @property bool $extended
 * @property string $ip_hash
 * @property CheckoutPath|null $path
 * @property string|null $stripe_checkout_session_id
 * @property Carbon|null $stripe_expires_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, RoomNightClaim> $claims
 */
#[Fillable([
    'token_hash',
    'cab'.'ins',
    'check_in',
    'check_out',
    'rooms',
    'guest',
    'status',
    'expires_at',
    'extended',
    'ip_hash',
    'path',
    'stripe_checkout_session_id',
    'stripe_expires_at',
])]
class CheckoutSession extends Model
{
    /** @use HasFactory<CheckoutSessionFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cab'.'ins' => 'array',
            'check_in' => CalendarDate::class,
            'check_out' => CalendarDate::class,
            'rooms' => 'array',
            'guest' => 'array',
            'status' => CheckoutSessionStatus::class,
            'expires_at' => 'datetime',
            'extended' => 'boolean',
            'path' => CheckoutPath::class,
            'stripe_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return MorphMany<RoomNightClaim, $this>
     */
    public function claims(): MorphMany
    {
        return $this->morphMany(RoomNightClaim::class, 'holder');
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeHolding(Builder $query): void
    {
        $query->where('status', CheckoutSessionStatus::Holding);
    }

    public function isStay(): bool
    {
        return $this->check_in !== null;
    }

    public function isExpired(): bool
    {
        return $this->status === CheckoutSessionStatus::Expired
            || ($this->status === CheckoutSessionStatus::Holding && $this->expires_at->isPast());
    }

    public function historyLabel(): string
    {
        return 'checkout '.$this->id;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function expiresAtIso(): string
    {
        return Iso::utc($this->expires_at);
    }
}
