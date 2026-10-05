<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Enums\ClaimKind;
use App\Exceptions\CabinUnavailableException;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Yacht 409 payload. ClaimService throws a room-and-night error. Callers that
 * still sell a departure rebuild the cabin list the panel already reads.
 */
final class CabinConflict
{
    /**
     * @param  Collection<int, Room>  $rooms
     */
    public static function exception(StayDates $stay, Collection $rooms, Model $holder): CabinUnavailableException
    {
        $conflicts = RoomNightClaim::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->whereDate('night', '>=', $stay->checkIn()->toDateString())
            ->whereDate('night', '<=', $stay->lastNight()->toDateString())
            ->whereNull('released_at')
            ->where(function ($query) use ($holder): void {
                $query->where('holder_type', '!=', $holder->getMorphClass())
                    ->orWhere('holder_id', '!=', $holder->getKey());
            })
            ->with(['room', 'holder'])
            ->orderBy('room_id')
            ->get()
            ->filter(fn (RoomNightClaim $claim): bool => ! self::isExpiredHold($claim))
            ->unique('room_id')
            ->values();

        $unavailable = $conflicts->map(function (RoomNightClaim $claim): array {
            $heldBy = $claim->holder;

            return [
                'cabin' => [
                    'id' => $claim->room->id,
                    'code' => $claim->room->code,
                    'label' => $claim->room->label,
                ],
                'held_by' => [
                    'kind' => $claim->kind->value,
                    'holder_type' => $claim->holder_type,
                    'reference' => self::holderReference($heldBy),
                ],
            ];
        })->values()->all();

        return new CabinUnavailableException($unavailable);
    }

    private static function isExpiredHold(RoomNightClaim $claim): bool
    {
        return $claim->kind === ClaimKind::Hold
            && $claim->expires_at !== null
            && $claim->expires_at->isPast();
    }

    private static function holderReference(?Model $holder): ?string
    {
        if (! $holder instanceof Model) {
            return null;
        }

        if (method_exists($holder, 'historyLabel')) {
            $label = $holder->historyLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $reference = $holder->getAttribute('reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
