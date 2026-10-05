<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Enums\RoomStatus;
use App\Events\AvailabilityChanged;
use App\Events\HoldExpired;
use App\Exceptions\RoomUnavailableException;
use App\Models\InternalBlock;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\StayRules;
use App\Support\History\History;
use App\Support\Inventory\RoomLocks;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function __construct(
        private readonly RoomAllocator $allocator,
        private readonly StayClock $clock,
        private readonly CurrentConfig $config,
    ) {}

    /**
     * One claim group, one row per room per night.
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, RoomNightClaim>
     */
    public function claim(
        StayDates $stay,
        Collection $rooms,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
    ): Collection {
        $this->guardTransaction();
        $this->assertClaimable($stay, $holder, $kind, $holdType, $expiresAt);

        $ordered = $rooms->sortBy('sort')->values();

        if ($ordered->isEmpty()) {
            return new Collection;
        }

        RoomLocks::lock($ordered->pluck('id'));
        $this->releaseExpiredHoldsFor($ordered->pluck('id'), $stay);

        try {
            $claims = $this->insertClaims($ordered, $stay->eachNight(), $holder, $kind, $holdType, $expiresAt);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflictOrRethrow($exception, $stay, $ordered);
        }

        $this->dispatchStay($ordered, $stay);

        return $claims;
    }

    /**
     * Lock every active room of the type, then let the allocator pick.
     *
     * @return Collection<int, RoomNightClaim>
     */
    public function claimType(
        StayDates $stay,
        RoomType $type,
        int $count,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
    ): Collection {
        $this->guardTransaction();

        if ($count < 1) {
            throw new InvalidArgumentException('A claim needs at least one room.');
        }

        $this->assertClaimable($stay, $holder, $kind, $holdType, $expiresAt);

        $ids = Room::query()
            ->where('room_type_id', $type->id)
            ->where('status', RoomStatus::Active)
            ->orderBy('id')
            ->pluck('id');

        RoomLocks::lock($ids);
        $this->releaseExpiredHoldsFor($ids, $stay);

        $picked = $this->allocator->pick($type, $stay, $count);

        try {
            $claims = $this->insertClaims($picked, $stay->eachNight(), $holder, $kind, $holdType, $expiresAt);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflictOrRethrow($exception, $stay, $picked);
        }

        $this->dispatchStay($picked, $stay);

        return $claims;
    }

    /**
     * @param  Collection<int, Room>|null  $rooms
     */
    public function release(
        Model $holder,
        ReleaseReason $reason,
        ?Collection $rooms = null,
        ?StayDates $nights = null,
    ): int {
        $this->guardTransaction();

        $active = $this->activeHolderClaims($holder, $rooms, $nights);

        if ($active->isEmpty()) {
            return 0;
        }

        $released = $this->releaseByIds($active->pluck('id')->all(), $reason);
        $this->dispatchClaims($active);

        return $released;
    }

    /**
     * Moves the active nights onto the new holder. Returns the number of rooms
     * moved (one per room per claim group), which is what a cabin count compares to.
     *
     * @param  Collection<int, Room>|null  $rooms
     */
    public function convert(
        Model $fromHolder,
        Model $toHolder,
        ClaimKind $kind,
        ?HoldType $holdType = null,
        ?CarbonInterface $expiresAt = null,
        ?Collection $rooms = null,
    ): int {
        $this->guardTransaction();

        if (self::$beforeConvert instanceof Closure) {
            (self::$beforeConvert)();
        }

        $active = $this->activeHolderClaims($fromHolder, $rooms, null);

        if ($active->isEmpty()) {
            return 0;
        }

        RoomLocks::lock($active->pluck('room_id'));

        $active = $this->activeHolderClaims($fromHolder, $rooms, null);

        if ($active->isEmpty()) {
            return 0;
        }

        $created = new Collection;

        foreach ($active->groupBy('claim_group') as $group) {
            /** @var Collection<int, RoomNightClaim> $group */
            $targetRooms = $group
                ->map(fn (RoomNightClaim $claim): Room => $claim->room)
                ->unique('id')
                ->sortBy('sort')
                ->values();
            $nightDates = $group
                ->map(fn (RoomNightClaim $claim): string => $claim->night->toDateString())
                ->unique()
                ->sort()
                ->values();
            $stay = StayDates::of(
                (string) $nightDates->first(),
                CarbonImmutable::parse((string) $nightDates->last())->addDay()->toDateString(),
            );

            $this->assertClaimable($stay, $toHolder, $kind, $holdType, $expiresAt);
            $this->releaseByIds($group->pluck('id')->all(), ReleaseReason::Converted);

            try {
                $created = $created->concat(
                    $this->insertClaims($targetRooms, $nightDates, $toHolder, $kind, $holdType, $expiresAt),
                );
            } catch (UniqueConstraintViolationException $exception) {
                throw $this->conflictOrRethrow($exception, $stay, $targetRooms);
            }
        }

        $this->dispatchClaims($created);

        return $created->unique(fn (RoomNightClaim $claim): string => $claim->claim_group.'-'.$claim->room_id)->count();
    }

    public function releaseExpired(): int
    {
        $released = 0;

        do {
            /** @var array{0: int, 1: int} $batch */
            $batch = DB::transaction(function (): array {
                $this->guardTransaction();

                $groups = RoomNightClaim::query()
                    ->where('kind', ClaimKind::Hold)
                    ->whereNull('released_at')
                    ->where('expires_at', '<', now())
                    ->select('claim_group')
                    ->distinct()
                    ->orderBy('claim_group')
                    ->limit(500)
                    ->pluck('claim_group');

                if ($groups->isEmpty()) {
                    return [0, 0];
                }

                $ids = RoomNightClaim::query()
                    ->whereIn('claim_group', $groups->all())
                    ->where('kind', ClaimKind::Hold)
                    ->whereNull('released_at')
                    ->where('expires_at', '<', now())
                    ->pluck('id')
                    ->all();

                $count = $this->releaseExpiredByIds($ids);

                if ($count > 0) {
                    $this->dispatchClaims(
                        RoomNightClaim::query()->whereIn('id', $ids)->with('room')->get(),
                    );
                }

                return [$groups->count(), $count];
            });

            $released += $batch[1];
        } while ($batch[0] === 500);

        return $released;
    }

    /**
     * @param  Collection<int, int>  $roomIds
     */
    private function releaseExpiredHoldsFor(Collection $roomIds, StayDates $stay): void
    {
        $ids = RoomNightClaim::query()
            ->whereIn('room_id', $roomIds->all())
            ->whereDate('night', '>=', $stay->checkIn()->toDateString())
            ->whereDate('night', '<=', $stay->lastNight()->toDateString())
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
        if ($ids === []) {
            return 0;
        }

        $now = now();

        $released = RoomNightClaim::query()
            ->whereIn('id', $ids)
            ->whereNull('released_at')
            ->where('expires_at', '<', $now)
            ->update([
                'released_at' => $now,
                'release_reason' => ReleaseReason::Expired,
                'updated_at' => $now,
            ]);

        $claims = RoomNightClaim::query()->whereIn('id', $ids)->with('holder')->get();

        foreach ($claims->groupBy(fn (RoomNightClaim $claim): string => $claim->claim_group.'|'.$claim->holder_type.'|'.$claim->holder_id) as $group) {
            $claim = $group->first();
            $holder = $claim?->holder;

            if ($claim instanceof RoomNightClaim && $holder instanceof Model) {
                History::record($holder, 'hold.expired', system: true);
                HoldExpired::dispatch($holder, $claim);
            }
        }

        return $released;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function releaseByIds(array $ids, ReleaseReason $reason): int
    {
        if ($ids === []) {
            return 0;
        }

        $now = now();

        return RoomNightClaim::query()
            ->whereIn('id', $ids)
            ->whereNull('released_at')
            ->update([
                'released_at' => $now,
                'release_reason' => $reason,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  Collection<int, Room>|null  $rooms
     * @return Collection<int, RoomNightClaim>
     */
    private function activeHolderClaims(Model $holder, ?Collection $rooms, ?StayDates $nights): Collection
    {
        $query = RoomNightClaim::query()
            ->where('holder_type', $holder->getMorphClass())
            ->where('holder_id', $holder->getKey())
            ->whereNull('released_at')
            ->with('room');

        if ($rooms instanceof Collection) {
            $query->whereIn('room_id', $rooms->pluck('id'));
        }

        if ($nights instanceof StayDates) {
            $query->whereDate('night', '>=', $nights->checkIn()->toDateString())
                ->whereDate('night', '<=', $nights->lastNight()->toDateString());
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  iterable<int, CarbonInterface|string>  $nights
     * @return Collection<int, RoomNightClaim>
     */
    private function insertClaims(
        Collection $rooms,
        iterable $nights,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType,
        ?CarbonInterface $expiresAt,
    ): Collection {
        $group = (string) Str::uuid();
        $claims = new Collection;
        $nightList = [];

        foreach ($nights as $night) {
            $nightList[] = $night instanceof CarbonInterface ? $night->toDateString() : $night;
        }

        foreach ($rooms as $room) {
            foreach ($nightList as $night) {
                $claims->push(RoomNightClaim::query()->create([
                    'room_id' => $room->id,
                    'night' => $night,
                    'holder_type' => $holder->getMorphClass(),
                    'holder_id' => $holder->getKey(),
                    'kind' => $kind,
                    'hold_type' => $holdType,
                    'expires_at' => $expiresAt,
                    'claim_group' => $group,
                ]));
            }
        }

        return $claims;
    }

    /**
     * @param  Collection<int, Room>  $rooms
     */
    private function conflictOrRethrow(
        UniqueConstraintViolationException $exception,
        StayDates $stay,
        Collection $rooms,
    ): RoomUnavailableException {
        if (! str_contains($exception->getMessage(), 'room_night_claims_active_key_unique')) {
            throw $exception;
        }

        $conflict = RoomNightClaim::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->whereDate('night', '>=', $stay->checkIn()->toDateString())
            ->whereDate('night', '<=', $stay->lastNight()->toDateString())
            ->whereNull('released_at')
            ->where(function ($query): void {
                $query->where('kind', '!=', ClaimKind::Hold->value)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->with('room.roomType')
            ->orderBy('night')
            ->orderBy('room_id')
            ->first();

        if ($conflict instanceof RoomNightClaim) {
            return new RoomUnavailableException(
                $conflict->room->roomType->name,
                $conflict->night->toDateString(),
            );
        }

        $room = $rooms->first();
        $room?->loadMissing('roomType');

        return new RoomUnavailableException(
            $room instanceof Room ? $room->roomType->name : 'Room',
            $stay->checkIn()->toDateString(),
        );
    }

    private function assertClaimable(
        StayDates $stay,
        Model $holder,
        ClaimKind $kind,
        ?HoldType $holdType,
        ?CarbonInterface $expiresAt,
    ): void {
        $today = $this->clock->today();

        if ($stay->checkIn()->toDateString() < $today->toDateString()) {
            throw new InvalidArgumentException('Cannot claim a room on a night in the past.');
        }

        $rules = $this->stayRules();

        if (! $holder instanceof InternalBlock) {
            $nights = $stay->nights();

            if ($nights < $rules->minNights || $nights > $rules->maxNights) {
                throw new InvalidArgumentException(
                    'A stay must be between '.$rules->minNights.' and '.$rules->maxNights.' nights.',
                );
            }
        }

        if ($stay->checkIn()->toDateString() > $today->addDays($rules->bookingHorizonDays)->toDateString()) {
            throw new InvalidArgumentException('Check-in is outside the booking horizon.');
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

    private function stayRules(): StayRules
    {
        if (! $this->config->has(ConfigKind::BusinessRules)) {
            return BusinessRulesDocument::fromArray(BusinessRulesDocument::initial())->stay;
        }

        return $this->config->businessRules()->stay;
    }

    /**
     * @param  Collection<int, Room>  $rooms
     */
    private function dispatchStay(Collection $rooms, StayDates $stay): void
    {
        foreach ($rooms->pluck('property_id')->unique() as $propertyId) {
            AvailabilityChanged::dispatch((int) $propertyId, $stay);
        }
    }

    /**
     * @param  Collection<int, RoomNightClaim>  $claims
     */
    private function dispatchClaims(Collection $claims): void
    {
        if ($claims->isEmpty()) {
            return;
        }

        $rooms = Room::query()->whereIn('id', $claims->pluck('room_id')->unique()->all())->get()->keyBy('id');

        foreach ($claims as $claim) {
            $room = $rooms->get($claim->room_id);

            if ($room instanceof Room) {
                $claim->setRelation('room', $room);
            }
        }

        foreach ($claims->groupBy(fn (RoomNightClaim $claim): int => (int) $claim->room->property_id) as $propertyId => $group) {
            /** @var Collection<int, RoomNightClaim> $group */
            $from = $group->min(fn (RoomNightClaim $claim): string => $claim->night->toDateString());
            $last = $group->max(fn (RoomNightClaim $claim): string => $claim->night->toDateString());

            AvailabilityChanged::dispatch(
                (int) $propertyId,
                StayDates::of((string) $from, CarbonImmutable::parse((string) $last)->addDay()->toDateString()),
            );
        }
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
