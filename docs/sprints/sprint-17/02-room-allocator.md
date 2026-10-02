# 17-02 — `RoomAllocator`

**Repo:** iconic-api
**Depends on:** 17-01
**Read first:** 09 H5, HQ4

## Why
Guests book a room **type**; the system must hold a specific **room** for all nights of the stay without fragmenting the house.

## Build
`App\Services\Inventory\RoomAllocator` — pure decision, no writes:
```php
public function pick(RoomType $type, StayDates $stay, int $count = 1, array $excludeRoomIds = []): Collection /* of Room */
```
1. Candidates: active rooms of the type, not in `$excludeRoomIds`, with **no** active, unexpired room-night claim on any night of the stay (one query: `whereNotExists` on claims with `night between check_in and check_out - 1`).
2. Score each candidate (lower is better), per 09 H5:
   - `0` if the room is claimed on the night **before** `check_in` **and** on the night `check_out` (stay fills a gap exactly);
   - `1` if claimed on one side;
   - `2` otherwise.
   Ties → `sort`, then `id`.
3. Return the best `$count` rooms or throw `RoomUnavailableException` (new, extends the existing 409 conflict contract; message names the type and the first night with no room).
4. `explain(RoomType, StayDates): array` — per night free-room counts, for error messages and the panel.

The allocator never locks. `ClaimService` (17-03) calls it **after** taking the locks and re-checks with the unique key.

## Tests (unit + database)
- Gap filling: rooms 101 (busy night before), 102 (free around) → picks 101.
- Fragmentation case: type has a free room every night but no single room for all nights → `RoomUnavailableException`, `explain()` shows ≥1 free each night.
- Inactive rooms never picked; expired holds count as free; released claims count as free.
- Determinism: same input, same output across 100 runs with shuffled insert order.

## Done when
The allocator has no `DB::` writes and 100% branch coverage.
