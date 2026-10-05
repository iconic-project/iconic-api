<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Enums\ClaimKind;
use App\Models\Departure;
use App\Models\RoomNightClaim;
use Illuminate\Support\Collection;

final class DepartureLocks
{
    public const HISTORY_DELETE = 'This departure has inventory history (released blocks or holds). Close or hide it instead.';

    /**
     * @param  Collection<int, RoomNightClaim>  $claims
     */
    public static function dateAndPropertyCount(Collection $claims): int
    {
        return $claims
            ->filter(fn (RoomNightClaim $claim): bool => $claim->released_at === null)
            ->filter(fn (RoomNightClaim $claim): bool => self::locksDateAndProperty($claim))
            ->count();
    }

    /**
     * @param  Collection<int, RoomNightClaim>  $claims
     * @return array{date_and_property: bool, delete: bool, reason: string|null}
     */
    public static function for(Collection $claims): array
    {
        $dateAndProperty = self::dateAndPropertyCount($claims);
        $active = $claims->filter(fn (RoomNightClaim $claim): bool => $claim->released_at === null);
        $hasAny = $claims->isNotEmpty();

        if ($active->isNotEmpty()) {
            return [
                'date_and_property' => $dateAndProperty > 0,
                'delete' => true,
                'reason' => self::activeDeleteMessage($active),
            ];
        }

        if ($hasAny) {
            return [
                'date_and_property' => false,
                'delete' => true,
                'reason' => self::HISTORY_DELETE,
            ];
        }

        return [
            'date_and_property' => false,
            'delete' => false,
            'reason' => null,
        ];
    }

    public static function dateAndPropertyMessage(int $count): string
    {
        return 'Date and property are locked — '.$count.' cabin(s) sold or held on this departure. Move guests with "Move to another departure" on each booking first.';
    }

    /**
     * @param  Collection<int, RoomNightClaim>  $active
     */
    public static function activeDeleteMessage(Collection $active): string
    {
        $blocked = $active->filter(fn (RoomNightClaim $claim): bool => $claim->kind === ClaimKind::Block)->count();
        $held = $active->filter(fn (RoomNightClaim $claim): bool => $claim->kind === ClaimKind::Hold)->count();
        $sold = $active->filter(fn (RoomNightClaim $claim): bool => $claim->kind === ClaimKind::Booking)->count();

        $parts = [];

        if ($blocked > 0) {
            $parts[] = $blocked.' blocked';
        }

        if ($held > 0) {
            $parts[] = $held.' held';
        }

        if ($sold > 0) {
            $parts[] = $sold.' sold';
        }

        return implode(', ', $parts);
    }

    /**
     * @return Collection<int, RoomNightClaim>
     */
    public static function claimsFor(Departure $departure): Collection
    {
        return DepartureNightClaims::forDeparture($departure);
    }

    public static function lock(int $id): Departure
    {
        return Departure::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Departure>
     */
    public static function lockMany(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $ids),
            fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        $locked = new Collection;

        foreach ($ids as $id) {
            $locked->put($id, self::lock($id));
        }

        return $locked;
    }

    private static function locksDateAndProperty(RoomNightClaim $claim): bool
    {
        if ($claim->kind === ClaimKind::Booking) {
            return true;
        }

        if ($claim->kind !== ClaimKind::Hold) {
            return false;
        }

        return $claim->expires_at === null || $claim->expires_at->isFuture();
    }
}
