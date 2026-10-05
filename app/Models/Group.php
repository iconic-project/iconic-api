<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use Database\Factories\GroupFactory;
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
 * @property string $reference
 * @property string $name
 * @property int|null $departure_id
 * @property int $coordinator_contact_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Departure|null $departure
 * @property-read Contact $coordinator
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable([
    'reference',
    'name',
    'departure_id',
    'coordinator_contact_id',
])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'coordinator_contact_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    /**
     * Same visibility as GET /groups and existing_group_id on create.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission(Permission::BookingsViewAll)) {
            return;
        }

        $query->whereHas('bookings', function (Builder $bookings) use ($user): void {
            $bookings->where('owner_id', $user->id);
        });
    }

    public function historyLabel(): string
    {
        return $this->reference;
    }
}
