<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * Rooms × nights for one property. `to` is exclusive.
 */
final readonly class NightGrid
{
    /**
     * @param  array{id: int, code: string, name: string}  $property
     * @param  list<string>  $nights
     * @param  list<array{
     *     id: int,
     *     code: string,
     *     label: string,
     *     sort: int,
     *     room_type: array{id: int, code: string, name: string},
     *     cells: list<array{
     *         night: string,
     *         state: string,
     *         claim: array{claim_group: string, reference: string|null, guest_surname: string|null, owner: string|null, holder_type: string, holder_id: int}|null
     *     }>
     * }>  $rooms
     * @param  array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>  $counts
     * @param  array{room_nights_available: int, room_nights_sold: int, pct: int}  $occupancy
     * @param  array{occupancy_pct: int, free_room_nights: int, nights_fully_sold: int, nights_below_threshold: int}  $kpis
     */
    public function __construct(
        public array $property,
        public string $from,
        public string $to,
        public array $nights,
        public array $rooms,
        public array $counts,
        public array $occupancy,
        public array $kpis,
    ) {}
}
