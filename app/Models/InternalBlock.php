<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\BlockReason;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property int $property_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property BlockReason $reason
 * @property string|null $notes
 * @property Carbon|null $released_at
 * @property int|null $released_by
 * @property string|null $release_note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read User|null $createdBy
 * @property-read User|null $releasedBy
 * @property-read Collection<int, RoomNightClaim> $claims
 */
#[Fillable([
    'reference',
    'property_id',
    'starts_on',
    'ends_on',
    'reason',
    'notes',
    'released_at',
    'released_by',
    'release_note',
])]
class InternalBlock extends Model
{
    use HasAuditColumns, SerializesDatesAsUtc;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => CalendarDate::class,
            'ends_on' => CalendarDate::class,
            'reason' => BlockReason::class,
            'released_at' => 'datetime',
        ];
    }

    public function delete(): ?bool
    {
        throw new LogicException('Internal blocks cannot be deleted.');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function historyLabel(): string
    {
        return $this->reference;
    }
}
