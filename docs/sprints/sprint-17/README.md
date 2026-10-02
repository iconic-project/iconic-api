# Sprint 17 — Night inventory

**Theme:** replace "a cabin on a departure" with "a room on a night" as the thing that is held, sold and blocked.

At the end of this sprint:
- `room_night_claims` is the inventory ledger; the database refuses to double-sell a room-night,
- `ClaimService` claims a **stay** for a **room** and the `RoomAllocator` picks the room,
- availability answers "how many rooms of type X are free on each night" and "can this stay be booked",
- internal blocks and sell restrictions work on date ranges,
- the panel calendar shows rooms × nights.

Yacht bookings keep working: every existing claim is backfilled into nights, and departure-based code paths call the new service through a thin adapter until Sprint 19 removes them.

**Read first (whole sprint):** 09 H1, H4, H5, H5b, H7, H19; `app/Services/Inventory/ClaimService.php`, `Availability.php`, `Support/Inventory/*`, `Actions/Blocks/*`, `laravel.mdc` (History exception for `HoldExpired`).

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Room-night claims table and backfill](01-room-night-claims.md) | api |
| 02 | [`RoomAllocator`](02-room-allocator.md) | api |
| 03 | [`ClaimService` on nights](03-claim-service.md) | api |
| 04 | [Availability by night](04-availability.md) | api |
| 05 | [Internal blocks over date ranges](05-blocks.md) | api |
| 06 | [Sell restrictions calendar](06-restrictions.md) | api |
| 07 | [Panel: rooms × nights calendar, blocks, restrictions](07-panel-calendar.md) | panel |
| 08 | [Sprint close: e2e and report](08-sprint-close.md) | api |

**E2E scenarios:** HINV-01 … HINV-06 (new); INV-01, INV-08, INV-09, INV-10 updated or retired (state which in the report).

## Concurrency is the risk of this sprint
The existing `tests/Concurrency` suite must be ported, not deleted. Two parallel claims for overlapping stays on the last free room: exactly one wins, the other gets 409, no orphan rows.
