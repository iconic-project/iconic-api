<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomTypeStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property string $code
 * @property string $name
 * @property int $base_occupancy
 * @property int $max_occupancy
 * @property int $max_adults
 * @property int $max_children
 * @property bool $waitlist_enabled
 * @property int $sort
 * @property RoomTypeStatus $status
 * @property string|null $slug
 * @property string|null $description
 * @property int|null $size_sqm
 * @property string|null $bed_setup
 * @property list<string>|null $amenities
 * @property list<array{path: string, alt: string|null}>|null $photos
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 */
#[Fillable([
    'property_id',
    'code',
    'name',
    'base_occupancy',
    'max_occupancy',
    'max_adults',
    'max_children',
    'waitlist_enabled',
    'sort',
    'status',
    'slug',
    'description',
    'size_sqm',
    'bed_setup',
    'amenities',
    'photos',
    'meta_title',
    'meta_description',
])]
class RoomType extends Model
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_occupancy' => 'integer',
            'max_occupancy' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'waitlist_enabled' => 'boolean',
            'sort' => 'integer',
            'status' => RoomTypeStatus::class,
            'size_sqm' => 'integer',
            'amenities' => 'array',
            'photos' => 'array',
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
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('sort');
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }
}
