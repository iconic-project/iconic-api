<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\RoomStatus;
use App\Exceptions\RoomUnavailableException;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Picks a specific room of a type that is free on every night of a stay.
 * Reads inventory. Writes nothing. Does not lock.
 */
final class RoomAllocator
{
    /**
     * @param  list<int>  $excludeRoomIds
     * @return Collection<int, Room>
     */
    public function pick(RoomType $type, StayDates $stay, int $count = 1, array $excludeRoomIds = []): Collection
    {
        $free = $this->candidates($type, $stay, $excludeRoomIds);

        if ($free->count() < $count) {
            throw new RoomUnavailableException(
                $type->name,
                $this->firstShortNight($type, $stay, $count, $excludeRoomIds),
            );
        }

        $edges = $this->edges($free->modelKeys(), $stay);

        return $free
            ->sort(fn (Room $left, Room $right): int => $this->compare($left, $right, $edges, $stay))
            ->take($count)
            ->values();
    }

    /**
     * Free active rooms of the type on each night of the stay.
     *
     * @return list<array{night: string, free: int}>
     */
    public function explain(RoomType $type, StayDates $stay): array
    {
        $free = $this->freeIdsByNight($type, $stay, []);
        $rows = [];

        foreach ($stay->eachNight() as $night) {
            $key = $night->toDateString();
            $rows[] = [
                'night' => $key,
                'free' => count($free[$key]),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $excludeRoomIds
     * @return Collection<int, Room>
     */
    private function candidates(RoomType $type, StayDates $stay, array $excludeRoomIds): Collection
    {
        $from = $stay->checkIn()->toDateString();
        $to = $stay->lastNight()->toDateString();

        return Room::query()
            ->where('room_type_id', $type->id)
            ->where('status', RoomStatus::Active)
            ->when($excludeRoomIds !== [], fn (EloquentBuilder $query): EloquentBuilder => $query->whereNotIn('id', $excludeRoomIds))
            ->whereNotExists(function (QueryBuilder $query) use ($from, $to): void {
                $query->selectRaw('1')
                    ->from('room_night_claims')
                    ->whereColumn('room_night_claims.room_id', 'rooms.id')
                    ->whereBetween('room_night_claims.night', [$from, $to]);
                $this->whereStillHeld($query);
            })
            ->get();
    }

    /**
     * @param  list<int>  $excludeRoomIds
     * @return array<string, list<int>>
     */
    private function freeIdsByNight(RoomType $type, StayDates $stay, array $excludeRoomIds): array
    {
        $roomQuery = Room::query()
            ->where('room_type_id', $type->id)
            ->where('status', RoomStatus::Active);

        if ($excludeRoomIds !== []) {
            $roomQuery->whereNotIn('id', $excludeRoomIds);
        }

        /** @var list<int> $roomIds */
        $roomIds = $roomQuery->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        /** @var array<string, array<int, true>> $blocked */
        $blocked = [];

        if ($roomIds !== []) {
            $claims = RoomNightClaim::query()
                ->whereIn('room_id', $roomIds)
                ->whereBetween('night', [$stay->checkIn()->toDateString(), $stay->lastNight()->toDateString()]);
            $this->whereStillHeld($claims);

            foreach ($claims->get(['room_id', 'night']) as $claim) {
                $blocked[$claim->night->toDateString()][(int) $claim->room_id] = true;
            }
        }

        $free = [];

        foreach ($stay->eachNight() as $night) {
            $key = $night->toDateString();
            $taken = array_keys($blocked[$key] ?? []);
            $free[$key] = array_values(array_diff($roomIds, $taken));
        }

        return $free;
    }

    /**
     * First night on which fewer than $count rooms are still free from check-in through that night.
     *
     * @param  list<int>  $excludeRoomIds
     */
    private function firstShortNight(RoomType $type, StayDates $stay, int $count, array $excludeRoomIds): string
    {
        $free = $this->freeIdsByNight($type, $stay, $excludeRoomIds);
        /** @var list<int>|null $running */
        $running = null;
        $night = $stay->checkIn()->toDateString();

        foreach ($stay->eachNight() as $date) {
            $key = $date->toDateString();
            $tonight = $free[$key];
            $running = $running === null ? $tonight : array_values(array_intersect($running, $tonight));

            if (count($running) < $count) {
                $night = $key;
                break;
            }
        }

        return $night;
    }

    /**
     * @param  list<int|string>  $roomIds
     * @return array<int, array<string, true>>
     */
    private function edges(array $roomIds, StayDates $stay): array
    {
        $before = $stay->checkIn()->subDay()->toDateString();
        $after = $stay->checkOut()->toDateString();

        $claims = RoomNightClaim::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('night', [$before, $after]);
        $this->whereStillHeld($claims);

        /** @var array<int, array<string, true>> $edges */
        $edges = [];

        foreach ($claims->get(['room_id', 'night']) as $claim) {
            $edges[(int) $claim->room_id][$claim->night->toDateString()] = true;
        }

        return $edges;
    }

    /**
     * @param  array<int, array<string, true>>  $edges
     */
    private function compare(Room $left, Room $right, array $edges, StayDates $stay): int
    {
        $byScore = $this->score($edges, $left->id, $stay) <=> $this->score($edges, $right->id, $stay);

        if ($byScore !== 0) {
            return $byScore;
        }

        $bySort = $left->sort <=> $right->sort;

        if ($bySort !== 0) {
            return $bySort;
        }

        return $left->id <=> $right->id;
    }

    /**
     * @param  array<int, array<string, true>>  $edges
     */
    private function score(array $edges, int $roomId, StayDates $stay): int
    {
        $before = isset($edges[$roomId][$stay->checkIn()->subDay()->toDateString()]);
        $after = isset($edges[$roomId][$stay->checkOut()->toDateString()]);

        if ($before && $after) {
            return 0;
        }

        if ($before || $after) {
            return 1;
        }

        return 2;
    }

    /**
     * @param  EloquentBuilder<RoomNightClaim>|QueryBuilder  $query
     */
    private function whereStillHeld(EloquentBuilder|QueryBuilder $query): void
    {
        $query->whereNull('released_at')
            ->where(function (EloquentBuilder|QueryBuilder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
    }
}
