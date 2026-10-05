<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $room_id
 * @property CarbonImmutable $night
 * @property string $holder_type
 * @property int $holder_id
 * @property ClaimKind $kind
 * @property HoldType|null $hold_type
 * @property Carbon|null $expires_at
 * @property Carbon|null $released_at
 * @property ReleaseReason|null $release_reason
 * @property string $claim_group
 * @property int|null $legacy_cabin_claim_id
 * @property string|null $active_key
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Room $room
 * @property-read Model $holder
 */
#[Fillable([
    'room_id',
    'night',
    'holder_type',
    'holder_id',
    'kind',
    'hold_type',
    'expires_at',
    'released_at',
    'release_reason',
    'claim_group',
    'legacy_cabin_claim_id',
])]
class RoomNightClaim extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /** @var list<string> */
    private const RELEASE_COLUMNS = [
        'released_at',
        'release_reason',
        'updated_at',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'night' => CalendarDate::class,
            'kind' => ClaimKind::class,
            'hold_type' => HoldType::class,
            'release_reason' => ReleaseReason::class,
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            foreach (array_keys($this->getDirty()) as $column) {
                if (! in_array($column, self::RELEASE_COLUMNS, true)) {
                    throw new LogicException("Room-night claim column [{$column}] cannot change.");
                }
            }
        }

        return parent::save($options);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        foreach (array_keys($attributes) as $column) {
            if (! in_array($column, self::RELEASE_COLUMNS, true)) {
                throw new LogicException("Room-night claim column [{$column}] cannot change.");
            }
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Room-night claims cannot be deleted.');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holder(): MorphTo
    {
        return $this->morphTo();
    }
}
