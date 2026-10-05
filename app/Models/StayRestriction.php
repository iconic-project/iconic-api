<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One night of sell rules. room_type_id null means every type at the property.
 * A type row replaces the property-wide row for that night; it does not inherit fields.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_type_id
 * @property CarbonImmutable $night
 * @property bool $stop_sell
 * @property bool $closed_to_arrival
 * @property bool $closed_to_departure
 * @property int|null $min_stay
 * @property int|null $max_stay
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read RoomType|null $roomType
 */
#[Fillable([
    'property_id',
    'room_type_id',
    'night',
    'stop_sell',
    'closed_to_arrival',
    'closed_to_departure',
    'min_stay',
    'max_stay',
    'note',
])]
class StayRestriction extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'night' => CalendarDate::class,
            'stop_sell' => 'boolean',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'min_stay' => 'integer',
            'max_stay' => 'integer',
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

    public function isDefault(): bool
    {
        return ! $this->stop_sell
            && ! $this->closed_to_arrival
            && ! $this->closed_to_departure
            && $this->min_stay === null
            && $this->max_stay === null
            && ($this->note === null || $this->note === '');
    }
}
