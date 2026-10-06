<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\PreferredChannel;
use App\Enums\WaitlistSource;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Carbon\CarbonImmutable;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_type_id
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $contact_id
 * @property int $adults
 * @property int $children
 * @property string|null $notes
 * @property WaitlistSource $source
 * @property Carbon|null $notified_at
 * @property int|null $notified_by
 * @property PreferredChannel|null $notified_channel
 * @property Carbon|null $removed_at
 * @property int|null $removed_by
 * @property string|null $removed_reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read RoomType $roomType
 * @property-read Contact $contact
 * @property-read User|null $notifiedBy
 * @property-read User|null $removedBy
 */
#[Fillable([
    'room_type_id',
    'check_in',
    'check_out',
    'contact_id',
    'adults',
    'children',
    'notes',
    'source',
    'notified_at',
    'notified_by',
    'notified_channel',
    'removed_at',
    'removed_by',
    'removed_reason',
    'created_at',
])]
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    public ?int $queuePosition = null;

    public bool $roomIsAvailable = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => CalendarDate::class,
            'check_out' => CalendarDate::class,
            'source' => WaitlistSource::class,
            'adults' => 'integer',
            'children' => 'integer',
            'notified_at' => 'datetime',
            'notified_channel' => PreferredChannel::class,
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function notifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
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
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    public function historyLabel(): string
    {
        $this->loadMissing('contact');

        return $this->contact->name;
    }
}
