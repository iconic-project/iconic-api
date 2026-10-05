<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Enums\Permission;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\Bookability;
use App\Services\Inventory\Restrictions;
use App\Support\Stays\StayDates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Staff sell paths. Engine and portal do not call this and cannot override.
 * No failing rules returns null, so booking history keeps a null reason.
 */
final class StaffStayRestrictions
{
    public function __construct(
        private readonly Restrictions $restrictions,
    ) {}

    /**
     * @param  iterable<Room>  $rooms
     * @param  array<string, mixed>  $data
     */
    public function check(iterable $rooms, StayDates $stay, User $actor, array $data): ?AppliedRestrictionOverride
    {
        return $this->decide(Bookability::ordered($this->reasons($rooms, $stay)), $actor, $data);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $data
     */
    public function decide(array $reasons, User $actor, array $data): ?AppliedRestrictionOverride
    {
        if ($reasons === []) {
            return null;
        }

        if (! $this->wantsOverride($data)) {
            throw ValidationException::withMessages([
                'stay' => $reasons,
            ]);
        }

        if (! $actor->hasPermission(Permission::BookingsOverrideRestrictions)) {
            throw new AuthorizationException('You cannot override sell restrictions.');
        }

        $key = array_key_exists('override_reason', $data) ? 'override_reason' : 'restriction_reason';
        $reason = $data[$key] ?? null;

        if (! is_string($reason) || trim($reason) === '') {
            throw ValidationException::withMessages([
                $key => ['A reason is required to override sell restrictions.'],
            ]);
        }

        return new AppliedRestrictionOverride(trim($reason), $reasons);
    }

    /**
     * @param  iterable<Room>  $rooms
     * @return list<string>
     */
    private function reasons(iterable $rooms, StayDates $stay): array
    {
        $models = new EloquentCollection;

        foreach ($rooms as $room) {
            $models->push($room);
        }

        $models->loadMissing('roomType');

        $reasons = [];
        $seen = [];

        foreach ($models as $room) {
            $type = $room->roomType;

            if (! $type instanceof RoomType || isset($seen[$type->id])) {
                continue;
            }

            $seen[$type->id] = true;

            foreach ($this->restrictions->evaluate($type, $stay)->reasons as $reason) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wantsOverride(array $data): bool
    {
        $value = $data['override_restrictions'] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }
}
