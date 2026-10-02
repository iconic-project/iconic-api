<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Events\AvailabilityChanged;
use App\Events\HoldExpired;
use App\Exceptions\CabinUnavailableException;
use App\Models\CabinClaim;
use App\Models\Departure;
use App\Models\Room;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class ClaimService
{
    public static int $baseTransactionLevel = 0;

    /**
     * Test seam only. Always null in production.
     *
     * @var (Closure(): void)|null
     */
    public static ?Closure $beforeConvert = null;

    /**
     * @param  Collection<int, Room>  $cabins
     * @return Collection<int, CabinClaim>
     */
    public function claim(
        Departure $departure,
        Collection $cabins,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
    ): Collection {
        $this->guardTransaction();
        $departure = DepartureLocks::lock((int) $departure->id);
        $this->assertClaimable($departure, $kind, $holdType, $expiresAt);

        $ordered = $cabins->sortBy('sort')->values();

        $this->releaseExpiredHoldsFor($departure, $ordered);

        try {
            $claims = $this->insertClaims($departure, $ordered, $holder, $kind, $holdType, $expiresAt);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflictOrRethrow($exception, $departure, $ordered, $holder);
        }

        $this->dispatchAvailability([$departure->id]);

        return $claims;
    }

    /**
     * @param  Collection<int, Room>|null  $cabins
     */
    public function release(Model $holder, ReleaseReason $reason, ?Collection $cabins = null): int
    {
        $this->guardTransaction();

        $ids = $this->activeHolderClaimIds($holder, $cabins);

        if ($ids === []) {
            return 0;
        }

        $released = $this->releaseByIds($ids, $reason);
        $departureIds = CabinClaim::query()->whereIn('id', $ids)->pluck('departure_id')->unique()->values()->all();
        $this->dispatchAvailability(array_map(intval(...), $departureIds));

        return $released;
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
        $this->guardTransaction();

        if (self::$beforeConvert instanceof Closure) {
            (self::$beforeConvert)();
        }

        $active = $this->activeHolderClaims($fromHolder, $cabins);

        if ($active->isEmpty()) {
            return 0;
        }

        DepartureLocks::lockMany(array_map(
            intval(...),
            $active->pluck('departure_id')->unique()->values()->all(),
        ));

        $active = $this->activeHolderClaims($fromHolder, $cabins);

        if ($active->isEmpty()) {
            return 0;
        }

        $sample = $active->first();
        $this->assertClaimable($sample->departure, $kind, $holdType, $expiresAt);

        $this->releaseByIds($active->pluck('id')->all(), ReleaseReason::Converted);

        $created = new Collection;

        foreach ($active->groupBy('departure_id') as $group) {
            /** @var Collection<int, CabinClaim> $group */
            $departure = $group->first()?->departure;
            if (! $departure instanceof Departure) {
                continue;
            }

            $targetCabins = $group->map(fn (CabinClaim $claim): Room => $claim->cabin)->sortBy('sort')->values();
            try {
                $created = $created->concat(
                    $this->insertClaims($departure, $targetCabins, $toHolder, $kind, $holdType, $expiresAt),
                );
            } catch (UniqueConstraintViolationException $exception) {
                throw $this->conflictOrRethrow($exception, $departure, $targetCabins, $toHolder);
            }
        }

        $this->dispatchAvailability(
            array_map(intval(...), $active->pluck('departure_id')->unique()->values()->all()),
        );

        return $created->count();
    }

    public function releaseExpired(): int
    {
        $released = 0;

        do {
            $batch = (int) DB::transaction(function (): int {
                $this->guardTransaction();

                $ids = CabinClaim::query()
                    ->where('kind', ClaimKind::Hold)
                    ->whereNull('released_at')
                    ->where('expires_at', '<', now())
                    ->orderBy('id')
                    ->limit(500)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                $count = $this->releaseExpiredByIds($ids);
                $departureIds = CabinClaim::query()
                    ->whereIn('id', $ids)
                    ->pluck('departure_id')
                    ->unique()
                    ->values()
                    ->all();

                if ($count > 0) {
                    $this->dispatchAvailability(array_map(intval(...), $departureIds));
                }

                return $count;
            });

            $released += $batch;
        } while ($batch === 500);

        return $released;
    }

    /**
     * @param  Collection<int, Room>  $cabins
     */
    private function releaseExpiredHoldsFor(Departure $departure, Collection $cabins): void
    {
        $ids = CabinClaim::query()
            ->where('departure_id', $departure->id)
            ->whereIn('room_id', $cabins->pluck('id'))
            ->where('kind', ClaimKind::Hold)
            ->whereNull('released_at')
            ->where('expires_at', '<', now())
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $this->releaseExpiredByIds($ids);
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function releaseExpiredByIds(array $ids): int
    {
        $released = 0;
        $now = now();

        foreach ($ids as $id) {
            $affected = CabinClaim::query()
                ->where('id', $id)
                ->whereNull('released_at')
                ->where('expires_at', '<', $now)
                ->update([
                    'released_at' => $now,
                    'release_reason' => ReleaseReason::Expired,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                continue;
            }

            $claim = CabinClaim::query()->with('holder')->find($id);
            $holder = $claim?->holder;

            if ($claim instanceof CabinClaim && $holder instanceof Model) {
                History::record($holder, 'hold.expired', system: true);
                HoldExpired::dispatch($holder, $claim);
            }

            $released++;
        }

        return $released;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function releaseByIds(array $ids, ReleaseReason $reason): int
    {
        $now = now();
        $released = 0;

        foreach ($ids as $id) {
            $affected = CabinClaim::query()
                ->where('id', $id)
                ->whereNull('released_at')
                ->update([
                    'released_at' => $now,
                    'release_reason' => $reason,
                    'updated_at' => $now,
                ]);

            $released += $affected;
        }

        return $released;
    }

    /**
     * @param  Collection<int, Room>|null  $cabins
     * @return list<int>
     */
    private function activeHolderClaimIds(Model $holder, ?Collection $cabins): array
    {
        return $this->activeHolderClaims($holder, $cabins)->pluck('id')->all();
    }

    /**
     * @param  Collection<int, Room>|null  $cabins
     * @return Collection<int, CabinClaim>
     */
    private function activeHolderClaims(Model $holder, ?Collection $cabins): Collection
    {
        $query = CabinClaim::query()
            ->where('holder_type', $holder->getMorphClass())
            ->where('holder_id', $holder->getKey())
            ->whereNull('released_at')
            ->with(['cabin', 'departure']);

        if ($cabins instanceof Collection) {
            $query->whereIn('room_id', $cabins->pluck('id'));
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Room>  $cabins
     * @return Collection<int, CabinClaim>
     */
    private function insertClaims(
        Departure $departure,
        Collection $cabins,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType,
        ?CarbonInterface $expiresAt,
    ): Collection {
        $claims = new Collection;

        foreach ($cabins as $cabin) {
            $claims->push(CabinClaim::query()->create([
                'departure_id' => $departure->id,
                'room_id' => $cabin->id,
                'holder_type' => $holder->getMorphClass(),
                'holder_id' => $holder->getKey(),
                'kind' => $kind,
                'hold_type' => $holdType,
                'expires_at' => $expiresAt,
            ]));
        }

        return $claims;
    }

    /**
     * @param  Collection<int, Room>  $cabins
     */
    private function conflictOrRethrow(
        UniqueConstraintViolationException $exception,
        Departure $departure,
        Collection $cabins,
        Model $holder,
    ): CabinUnavailableException {
        if (! str_contains($exception->getMessage(), 'cabin_claims_active_key_unique')) {
            throw $exception;
        }

        return $this->unavailable($departure, $cabins, $holder);
    }

    /**
     * @param  Collection<int, Room>  $cabins
     */
    private function unavailable(Departure $departure, Collection $cabins, Model $holder): CabinUnavailableException
    {
        $conflicts = CabinClaim::query()
            ->where('departure_id', $departure->id)
            ->whereIn('room_id', $cabins->pluck('id'))
            ->whereNull('released_at')
            ->where(function ($query) use ($holder): void {
                $query->where('holder_type', '!=', $holder->getMorphClass())
                    ->orWhere('holder_id', '!=', $holder->getKey());
            })
            ->with(['cabin', 'holder'])
            ->get()
            ->filter(fn (CabinClaim $claim): bool => ! $this->isExpiredHold($claim));

        $unavailable = $conflicts->map(function (CabinClaim $claim): array {
            $holder = $claim->holder;

            return [
                'cabin' => [
                    'id' => $claim->cabin->id,
                    'code' => $claim->cabin->code,
                    'label' => $claim->cabin->label,
                ],
                'held_by' => [
                    'kind' => $claim->kind->value,
                    'holder_type' => $claim->holder_type,
                    'reference' => $this->holderReference($holder),
                ],
            ];
        })->values()->all();

        return new CabinUnavailableException($unavailable);
    }

    private function isExpiredHold(CabinClaim $claim): bool
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

    private function assertClaimable(
        Departure $departure,
        ClaimKind $kind,
        ?HoldType $holdType,
        ?CarbonInterface $expiresAt,
    ): void {
        if ($departure->date->toDateString() < BusinessTime::now()->toDateString()) {
            throw new InvalidArgumentException('Cannot claim a cabin on a departure in the past.');
        }

        if ($kind === ClaimKind::Hold) {
            if ($expiresAt === null) {
                throw new InvalidArgumentException('A HOLD claim requires an expiry.');
            }

            if ($expiresAt->isPast()) {
                throw new InvalidArgumentException('A HOLD claim cannot expire in the past.');
            }

            if ($holdType === null) {
                throw new InvalidArgumentException('A HOLD claim requires a hold type.');
            }

            return;
        }

        if ($expiresAt !== null) {
            throw new InvalidArgumentException('Only HOLD claims may have an expiry.');
        }

        if ($holdType !== null) {
            throw new InvalidArgumentException('Only HOLD claims may have a hold type.');
        }
    }

    /**
     * @param  list<int>  $departureIds
     */
    private function dispatchAvailability(array $departureIds): void
    {
        $ids = array_values(array_unique(array_filter($departureIds, fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        AvailabilityChanged::dispatch($ids);
    }

    private function guardTransaction(): void
    {
        if (DB::transactionLevel() > self::$baseTransactionLevel) {
            return;
        }

        if (app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Claims must be written inside a transaction.');
        }

        Log::warning('ClaimService was called outside a database transaction.');
    }
}
