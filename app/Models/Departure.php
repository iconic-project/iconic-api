<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\DepartureStatus;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Inventory\DepartureSnapshot;
use Carbon\CarbonImmutable;
use Database\Factories\DepartureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $reference
 * @property CarbonImmutable $date
 * @property int $property_id
 * @property int $itinerary_id
 * @property DepartureStatus $status
 * @property int $urgency_threshold
 * @property bool $waitlist_enabled
 * @property string|null $public_note
 * @property bool $festive
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read Itinerary $itinerary
 * @property-read Collection<int, CabinClaim> $claims
 */
#[Fillable([
    'reference',
    'date',
    'property_id',
    'itinerary_id',
    'status',
    'urgency_threshold',
    'waitlist_enabled',
    'public_note',
    'festive',
])]
class Departure extends Model
{
    /** @use HasFactory<DepartureFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    public ?DepartureSnapshot $snapshot = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => CalendarDate::class,
            'status' => DepartureStatus::class,
            'urgency_threshold' => 'integer',
            'waitlist_enabled' => 'boolean',
            'festive' => 'boolean',
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
     * @return BelongsTo<Itinerary, $this>
     */
    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    /**
     * @return HasMany<CabinClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(CabinClaim::class);
    }

    public const DEFAULT_NIGHTS = 7;

    public function returnDate(): CarbonImmutable
    {
        $this->loadMissing('itinerary');

        $nights = $this->itinerary->nights;

        return $this->date->addDays($nights > 0 ? $nights : self::DEFAULT_NIGHTS);
    }

    public function historyLabel(): string
    {
        return $this->reference;
    }
}
