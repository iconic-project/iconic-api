<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckoutPath;
use App\Enums\CheckoutSessionStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Iso;
use Database\Factories\CheckoutSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $token_hash
 * @property int $departure_id
 * @property list<array{cabin_code: string, adults: int, children: int}> $cabins
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
 * @property-read Departure $departure
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, RoomNightClaim> $claims
 */
#[Fillable([
    'token_hash',
    'departure_id',
    'cabins',
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
            'cabins' => 'array',
            'status' => CheckoutSessionStatus::class,
            'expires_at' => 'datetime',
            'extended' => 'boolean',
            'path' => CheckoutPath::class,
            'stripe_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
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
