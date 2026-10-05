<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Exceptions\CabinUnavailableException;
use App\Exceptions\RoomUnavailableException;
use App\Models\Departure;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Support\Inventory\DepartureLocks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Temporary yacht entry. Sprint 19 deletes this and the callers move to stays.
 * Locks the departure first so a date change still waits, then writes nights.
 */
final class LegacyDepartureClaims
{
    public function __construct(private readonly ClaimService $claims) {}

    /**
     * @param  Collection<int, Room>  $cabins
     * @return Collection<int, RoomNightClaim>
     */
    public function claim(
        Departure $departure,
        Collection $cabins,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
    ): Collection {
        DepartureLocks::lock((int) $departure->id);

        $rooms = $cabins->sortBy('sort')->values();

        try {
            return $this->claims->claim(
                $departure->stayDates(),
                $rooms,
                $holder,
                $kind,
                $holdType,
                $expiresAt,
            );
        } catch (RoomUnavailableException) {
            throw $this->unavailable($departure, $rooms, $holder);
        }
    }

    /**
     * @param  Collection<int, Room>|null  $cabins
     */
    public function release(Model $holder, ReleaseReason $reason, ?Collection $cabins = null): int
    {
        return $this->claims->release($holder, $reason, $cabins);
    }

    /**
     * @param  Collection<int, Room>|null  $cabins
     */
    public function convert(
        Model $fromHolder,
        Model $toHolder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
        ?Collection $cabins = null,
    ): int {
        return $this->claims->convert($fromHolder, $toHolder, $kind, $holdType, $expiresAt, $cabins);
    }

    public function releaseExpired(): int
    {
        return $this->claims->releaseExpired();
    }

    /**
     * @param  Collection<int, Room>  $rooms
     */
    private function unavailable(Departure $departure, Collection $rooms, Model $holder): CabinUnavailableException
    {
        $stay = $departure->stayDates();

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
            ->filter(fn (RoomNightClaim $claim): bool => ! $this->isExpiredHold($claim))
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
                    'reference' => $this->holderReference($heldBy),
                ],
            ];
        })->values()->all();

        return new CabinUnavailableException($unavailable);
    }

    private function isExpiredHold(RoomNightClaim $claim): bool
    {
        return $claim->kind === ClaimKind::Hold
            && $claim->expires_at !== null
            && $claim->expires_at->isPast();
    }

    private function holderReference(?Model $holder): ?string
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
