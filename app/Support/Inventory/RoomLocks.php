<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Room;
use Illuminate\Support\Collection;

final class RoomLocks
{
    /**
     * Lock rooms in id order so two claims cannot deadlock (09 H5b).
     *
     * @param  Collection<int, int>  $roomIds
     */
    public static function lock(Collection $roomIds): void
    {
        $ids = $roomIds
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Room::query()->whereIn('id', $ids->all())->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
