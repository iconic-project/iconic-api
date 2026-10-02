<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $departure_id
 * @property int $room_id
 * @property string $holder_type
 * @property int $holder_id
 * @property ClaimKind $kind
 * @property HoldType|null $hold_type
 * @property Carbon|null $expires_at
 * @property Carbon|null $released_at
 * @property ReleaseReason|null $release_reason
 * @property string|null $active_key
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Departure $departure
 * @property-read Room $cabin
 * @property-read Model $holder
 */
#[Fillable([
    'departure_id',
    'room_id',
    'holder_type',
    'holder_id',
    'kind',
    'hold_type',
    'expires_at',
    'released_at',
    'release_reason',
])]
class CabinClaim extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ClaimKind::class,
            'hold_type' => HoldType::class,
            'release_reason' => ReleaseReason::class,
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function delete(): ?bool
    {
        throw new LogicException('Cabin claims cannot be deleted.');
    }

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function cabin(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holder(): MorphTo
    {
        return $this->morphTo();
    }
}
