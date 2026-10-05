<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CabinCategory;
use App\Enums\RoomStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property int $room_type_id
 * @property string $code
 * @property string $label
 * @property string|null $floor
 * @property int $sort
 * @property RoomStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read RoomType $roomType
 * @property-read Collection<int, RoomNightClaim> $nightClaims
 */
#[Fillable([
    'property_id',
    'room_type_id',
    'code',
    'label',
    'floor',
    'sort',
    'status',
])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'status' => RoomStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<RoomNightClaim, $this>
     */
    public function nightClaims(): HasMany
    {
        return $this->hasMany(RoomNightClaim::class);
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    // TODO(Sprint 18): room type pricing (09 H8)
    public function pricingCategory(): CabinCategory
    {
        $this->loadMissing('roomType');

        return $this->roomType->code === CabinCategory::Owner->value
            ? CabinCategory::Owner
            : CabinCategory::Suite;
    }
}
