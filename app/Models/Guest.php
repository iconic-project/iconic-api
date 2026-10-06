<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\SensitiveEncrypted;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Guests\Age;
use Carbon\CarbonImmutable;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $booking_id
 * @property int $position
 * @property bool $is_lead
 * @property int|null $lead_key
 * @property string $first_name
 * @property string $last_name
 * @property CarbonImmutable|null $dob
 * @property string|null $nationality
 * @property bool $ecuador_resident
 * @property string|null $passport_no
 * @property CarbonImmutable|null $passport_expiry
 * @property string|null $email
 * @property bool $insurance_declared
 * @property string|null $medical_note
 * @property string|null $dietary_note
 * @property string|null $accessibility_note
 * @property string|null $guardian_name
 * @property string|null $guardian_relationship
 * @property Carbon|null $guardian_consented_at
 * @property int|null $guardian_recorded_by
 * @property int|null $png_fee
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking $booking
 * @property-read User|null $guardianRecordedBy
 */
#[Fillable([
    'booking_id',
    'position',
    'is_lead',
    'first_name',
    'last_name',
    'dob',
    'nationality',
    'ecuador_resident',
    'passport_no',
    'passport_expiry',
    'email',
    'insurance_declared',
    'medical_note',
    'dietary_note',
    'accessibility_note',
    'guardian_name',
    'guardian_relationship',
    'guardian_consented_at',
    'guardian_recorded_by',
    'png_fee',
])]
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_lead' => 'boolean',
            'dob' => CalendarDate::class,
            'ecuador_resident' => 'boolean',
            'passport_no' => SensitiveEncrypted::class,
            'passport_expiry' => CalendarDate::class,
            'insurance_declared' => 'boolean',
            'medical_note' => SensitiveEncrypted::class,
            'dietary_note' => SensitiveEncrypted::class,
            'accessibility_note' => SensitiveEncrypted::class,
            'guardian_consented_at' => 'datetime',
            'png_fee' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<GuestPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(GuestPreference::class);
    }

    /**
     * Latest version that retention has not purged.
     *
     * @return HasOne<GuestPreference, $this>
     */
    public function currentPreference(): HasOne
    {
        return $this->hasOne(GuestPreference::class)->ofMany(
            ['version' => 'max'],
            function (Builder $query): void {
                $query->whereNull('purged_at');
            },
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardianRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_recorded_by');
    }

    public function displayName(): string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name !== '' ? $name : 'Guest '.$this->position;
    }

    public function historyLabel(): string
    {
        return $this->displayName();
    }

    public function isComplete(): bool
    {
        return $this->first_name !== ''
            && $this->last_name !== ''
            && $this->dob !== null
            && $this->nationality !== null
            && $this->nationality !== ''
            && $this->passport_no !== null
            && $this->passport_no !== ''
            && $this->passport_expiry !== null
            && $this->insurance_declared;
    }

    public function isMinorNow(): bool
    {
        return Age::isMinorNow($this->dob);
    }

    /**
     * Named passengers only — empty padded slots (no first or last name) are excluded.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNamed(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->where($inner->qualifyColumn('first_name'), '!=', '')
                ->orWhere($inner->qualifyColumn('last_name'), '!=', '');
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeComplete(Builder $query): void
    {
        $query
            ->where('first_name', '!=', '')
            ->where('last_name', '!=', '')
            ->whereNotNull('dob')
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->whereNotNull('passport_no')
            ->whereNotNull('passport_expiry')
            ->where('insurance_declared', true);
    }
}
