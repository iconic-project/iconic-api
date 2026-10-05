<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\CabinState;
use App\Enums\ClaimKind;
use App\Enums\DepartureStatus;
use App\Enums\EngineLabelCode;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\InternalBlock;
use App\Models\RoomNightClaim;
use App\Support\Inventory\DepartureLocks;
use App\Support\Inventory\DepartureNightClaims;
use App\Support\Inventory\DepartureSnapshot;
use App\Support\Inventory\EngineLabel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class Availability
{
    /**
     * @param  Collection<int, Departure>  $departures
     * @return array<int, DepartureSnapshot>
     */
    public function forDepartures(Collection $departures): array
    {
        if ($departures->isEmpty()) {
            return [];
        }

        $models = $departures instanceof EloquentCollection
            ? $departures
            : new EloquentCollection($departures->all());

        $models->loadMissing(['property.cabins.roomType', 'itinerary']);

        $claims = DepartureNightClaims::byDeparture($models);

        $result = [];

        foreach ($models as $departure) {
            $result[$departure->id] = $this->forOne($departure, $claims[$departure->id] ?? new Collection);
        }

        return $result;
    }

    /**
     * @param  array<int, DepartureSnapshot>  $snapshots
     * @param  Collection<int, Departure>  $departures
     * @return array{on_sale_on_engine: int, cabins_bookable: int, showing_only_n_left: int, full: int}
     */
    public function kpis(Collection $departures, array $snapshots): array
    {
        $live = $departures->filter(function (Departure $departure) use ($snapshots): bool {
            if ($departure->status !== DepartureStatus::OnSale) {
                return false;
            }

            $code = $snapshots[$departure->id]->engineLabel['code'] ?? null;

            return $code !== EngineLabelCode::NotShown->value
                && $code !== EngineLabelCode::Chartered->value;
        });

        $bookable = 0;
        $onlyN = 0;
        $full = 0;

        foreach ($live as $departure) {
            $counts = $snapshots[$departure->id]->counts;
            $bookable += $counts['free'];

            if ($counts['free'] === 0) {
                $full++;
            }

            if ($counts['free'] > 0 && $counts['free'] <= $departure->urgency_threshold) {
                $onlyN++;
            }
        }

        return [
            'on_sale_on_engine' => $live->count(),
            'cabins_bookable' => $bookable,
            'showing_only_n_left' => $onlyN,
            'full' => $full,
        ];
    }

    /**
     * @param  Collection<int, RoomNightClaim>  $claims
     */
    private function forOne(Departure $departure, Collection $claims): DepartureSnapshot
    {
        $cabins = $departure->property->cabins;
        $activeByCabin = $claims
            ->filter(fn (RoomNightClaim $claim): bool => $claim->released_at === null)
            ->filter(fn (RoomNightClaim $claim): bool => ! $this->isExpiredHold($claim))
            ->keyBy('room_id');

        $rows = [];
        $sold = 0;
        $held = 0;
        $blocked = 0;
        $free = 0;
        $suitesFree = 0;
        $ownerFree = false;
        $soldHolderKeys = [];

        foreach ($cabins as $cabin) {
            $claim = $activeByCabin->get($cabin->id);
            $state = $this->state($claim);
            $rows[] = [
                'cabin' => [
                    'code' => $cabin->code,
                    'label' => $cabin->label,
                    'category' => $cabin->roomType->code,
                ],
                'state' => $state->value,
                'claim' => $claim instanceof RoomNightClaim ? $this->claimSummary($claim) : null,
            ];

            match ($state) {
                CabinState::Sold => $sold++,
                CabinState::Held => $held++,
                CabinState::Blocked => $blocked++,
                CabinState::Free => $free++,
            };

            if ($state === CabinState::Free) {
                if ($cabin->roomType->code === 'OWNER') {
                    $ownerFree = true;
                } else {
                    $suitesFree++;
                }
            }

            if ($state === CabinState::Sold && $claim instanceof RoomNightClaim) {
                $soldHolderKeys[] = $claim->holder_type.'|'.$claim->holder_id;
            }
        }

        $chartered = $sold === $cabins->count()
            && $cabins->count() === 9
            && count(array_unique($soldHolderKeys)) === 1;

        $counts = [
            'sold' => $sold,
            'held' => $held,
            'blocked' => $blocked,
            'free' => $free,
            'suites_free' => $suitesFree,
            'owner_free' => $ownerFree,
        ];

        return new DepartureSnapshot(
            $rows,
            $counts,
            EngineLabel::for(
                $departure->status,
                $departure->itinerary->status,
                $chartered,
                $free,
                $held,
                $departure->urgency_threshold,
                $departure->waitlist_enabled,
            ),
            DepartureLocks::for($claims),
        );
    }

    private function state(?RoomNightClaim $claim): CabinState
    {
        if (! $claim instanceof RoomNightClaim) {
            return CabinState::Free;
        }

        return match ($claim->kind) {
            ClaimKind::Hold => CabinState::Held,
            ClaimKind::Booking => CabinState::Sold,
            ClaimKind::Block => CabinState::Blocked,
        };
    }

    /**
     * @return array{kind: string, hold_type: string|null, expires_at: string|null, holder: array{type: string, id: int, reference: string|null, label: string|null, detail: array{reason: string, reason_label: string}|array{status: string, type: string, segment: string, display_reference: string|null, owner_id: int, owner_name: string, party_label: string, hold_expired: bool}|null}}
     */
    private function claimSummary(RoomNightClaim $claim): array
    {
        $holder = $claim->holder;

        return [
            'kind' => $claim->kind->value,
            'hold_type' => $claim->hold_type?->value,
            'expires_at' => $claim->expires_at?->toJSON(),
            'holder' => [
                'type' => $claim->holder_type,
                'id' => $claim->holder_id,
                'reference' => $this->holderField($holder, 'reference'),
                'label' => $this->holderLabel($holder),
                'detail' => $this->holderDetail($holder),
            ],
        ];
    }

    /**
     * @return array{reason: string, reason_label: string}|array{status: string, type: string, segment: string, display_reference: string|null, owner_id: int, owner_name: string, party_label: string, hold_expired: bool}|null
     */
    private function holderDetail(?Model $holder): ?array
    {
        if ($holder instanceof InternalBlock) {
            return [
                'reason' => $holder->reason->value,
                'reason_label' => $holder->reason->label(),
            ];
        }

        if ($holder instanceof Booking) {
            $holder->loadMissing(['owner', 'bookingRequest']);

            return [
                'status' => $holder->status->value,
                'type' => $holder->type->value,
                'segment' => $holder->segment()->value,
                'display_reference' => $holder->displayReference(),
                'owner_id' => $holder->owner_id,
                'owner_name' => $holder->owner->name,
                'party_label' => $holder->partyLabel(),
                'hold_expired' => $holder->holdExpired(),
            ];
        }

        return null;
    }

    private function holderLabel(?Model $holder): ?string
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

        foreach (['reference', 'name'] as $attribute) {
            $value = $this->holderField($holder, $attribute);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function holderField(?Model $holder, string $attribute): ?string
    {
        if (! $holder instanceof Model) {
            return null;
        }

        $value = $holder->getAttribute($attribute);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isExpiredHold(RoomNightClaim $claim): bool
    {
        return $claim->kind === ClaimKind::Hold
            && $claim->expires_at !== null
            && $claim->expires_at->isPast();
    }
}
