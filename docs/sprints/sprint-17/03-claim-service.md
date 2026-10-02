# 17-03 — `ClaimService` on nights

**Repo:** iconic-api
**Depends on:** 17-01, 17-02
**Read first:** 09 H4, H5b; current `ClaimService.php` (all of it), `Support/Inventory/DepartureLocks.php`, `Events/AvailabilityChanged.php`, `Events/HoldExpired.php`, `Listeners/MarkRequestHoldExpired.php`, `tests/Concurrency/*`

## Why
Every hold, booking and block goes through `ClaimService`. Its contract changes from `(Departure, Cabins)` to `(StayDates, Rooms)`; its guarantees must not.

## Build
1. New signatures (same class, same guarantees: must run inside a transaction — keep `guardTransaction()`):
   ```php
   claim(StayDates $stay, Collection $rooms, Model $holder, ClaimKind $kind, ?HoldType $holdType = null, ?CarbonInterface $expiresAt = null): Collection
   claimType(StayDates $stay, RoomType $type, int $count, Model $holder, ClaimKind $kind, …): Collection   // allocator + claim
   release(Model $holder, ReleaseReason $reason, ?Collection $rooms = null, ?StayDates $nights = null): int
   convert(Model $from, Model $to, ClaimKind $kind, …): int
   releaseExpired(): int
   ```
   `release(..., $nights)` releases only those nights — needed for shortening a stay (Sprint 19).
2. Locking (09 H5b): `RoomLocks::lock(Collection $roomIds)` — `SELECT … FROM rooms WHERE id IN (…) ORDER BY id FOR UPDATE`. For `claimType`, lock **all active rooms of the type** (small set; avoids allocator races). Delete `DepartureLocks::lock` usage from this service.
3. Claimability (`assertClaimable`): arrival not before `StayClock::today()`; `nights` between `stay.min_nights` and `stay.max_nights` unless the holder is an `InternalBlock`; `check_in` within `stay.booking_horizon_days`; hold kinds need `expiresAt`. Restriction checks (17-06) are **not** here — they are a sales rule, checked by callers, so staff overrides stay possible.
4. Expired holds: before inserting, release expired holds on the same rooms/nights (same as today's `releaseExpiredHoldsFor`), dispatching `HoldExpired` **in-transaction** (the documented exception). The request stays REQUESTED.
5. `AvailabilityChanged` now carries `property_id` and a `StayDates` range (union of touched nights) instead of departure ids. Update `BumpEngineFeedVersion` to bump on any change.
6. **Yacht adapter** (temporary, deleted in Sprint 19): `LegacyDepartureClaims` translates the old calls `(Departure, Cabins)` into `claim($departure->stayDates(), $rooms, …)`. Point every existing caller (`Actions/Bookings/*`, `Actions/Blocks/*`, checkout, charter, agency hold) at the adapter in this task, without changing their behaviour.
7. Remove the `cabin_claims` write path entirely (trigger from 17-01 now refuses inserts).

## Tests
- Port every `ClaimService` unit/feature test to nights; keep the old test names with a `(nights)` suffix so reviewers can diff.
- Concurrency (port `tests/Concurrency`): two processes claim overlapping stays on the last room → one 201, one 409; adjacent stays (A out = B in) on the same room → both succeed.
- Partial release of 2 nights out of 5 leaves 3 active, same `claim_group`.
- `HoldExpired` is dispatched synchronously and `booking_requests.hold_expired_at` is set in the same transaction.

## Done when
`grep -rn "DepartureLocks::lock\|CabinClaim::create\|cabin_claims" app/` returns nothing outside the adapter and read-only history views.
