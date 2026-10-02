<?php

declare(strict_types=1);

namespace App\Support\Rooms;

use App\Enums\ClaimKind;
use App\Exceptions\ConflictException;
use App\Models\CabinClaim;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;

final class FutureClaimGuard
{
    /**
     * @param  Collection<int, int>|list<int>  $roomIds
     */
    public function assertNone(Collection|array $roomIds, string $message): void
    {
        $ids = $roomIds instanceof Collection ? $roomIds->all() : $roomIds;

        if ($ids === []) {
            return;
        }

        $today = BusinessTime::now()->toDateString();

        $blocked = CabinClaim::query()
            ->whereIn('room_id', $ids)
            ->whereNull('released_at')
            ->where(function ($query): void {
                $query->where('kind', '!=', ClaimKind::Hold->value)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereHas('departure', fn ($query) => $query->whereDate('date', '>=', $today))
            ->exists();

        if ($blocked) {
            throw new ConflictException($message);
        }
    }
}
