<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\BookabilityReason;

/**
 * Whether a stay can be placed on one room of a type for every night.
 *
 * Reasons use the stable order on StayRestrictionResource::REASON_ORDER:
 * STOP_SELL, CLOSED_TO_ARRIVAL, CLOSED_TO_DEPARTURE, MIN_STAY:n, MAX_STAY:n,
 * SOLD_OUT, NO_SINGLE_ROOM. ok is false when any reason is present.
 * A short night is SOLD_OUT only. NO_SINGLE_ROOM is used when every night
 * has enough free rooms but no single room covers the whole stay.
 */
final readonly class Bookability
{
    /**
     * @param  list<string>  $reasons
     * @param  list<array{night: string, free: int}>  $nights
     */
    public function __construct(
        public bool $ok,
        public array $reasons,
        public array $nights,
    ) {}

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    public static function ordered(array $reasons): array
    {
        $unique = array_values(array_unique($reasons));

        usort($unique, function (string $left, string $right): int {
            $rank = self::rank($left) <=> self::rank($right);

            return $rank !== 0 ? $rank : strcmp($left, $right);
        });

        return $unique;
    }

    private static function rank(string $reason): int
    {
        return match (true) {
            $reason === 'STOP_SELL' => 1,
            $reason === 'CLOSED_TO_ARRIVAL' => 2,
            $reason === 'CLOSED_TO_DEPARTURE' => 3,
            str_starts_with($reason, 'MIN_STAY:') => 4,
            str_starts_with($reason, 'MAX_STAY:') => 5,
            $reason === BookabilityReason::SoldOut->value => 6,
            $reason === BookabilityReason::NoSingleRoom->value => 7,
            default => 9,
        };
    }
}
