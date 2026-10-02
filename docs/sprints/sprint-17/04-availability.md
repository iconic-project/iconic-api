# 17-04 — Availability by night

**Repo:** iconic-api
**Depends on:** 17-03
**Read first:** 09 H4, H7; current `Services/Inventory/Availability.php`, `Support/Inventory/DepartureSnapshot.php`, `EngineLabel.php`, `Snapshots.php`, `CalendarController`

## Why
Staff and the engine now ask "what is free between these dates", not "what is free on this departure".

## Build
1. `App\Services\Inventory\NightAvailability`:
   - `grid(Property $p, CarbonInterface $from, CarbonInterface $to): NightGrid` — for each night in `[from, to)` and each room: state `FREE | HELD | SOLD | BLOCKED` (reuse `CabinState` values; rename the enum to `RoomNightState` with a deprecated alias) plus claim summary (`claim_group`, holder reference, guest surname, owner) — one query for claims, one for rooms. Max range 62 nights (validated).
   - `countsByType(Property, from, to): array<night, array<room_type_code, {total, free, held, sold, blocked}>>`.
   - `canBook(RoomType, StayDates, int $rooms = 1): Bookability` — `{ok: bool, reason: null|SOLD_OUT|NO_SINGLE_ROOM, nights: [...]}` using the allocator's `explain()`; restrictions are added in 17-06.
   - `occupancy(Property, from, to): {room_nights_available, room_nights_sold, pct}` (blocked nights excluded from available; out-of-order definition in REPORT).
2. Expired holds count as free (same as today's `isExpiredHold`).
3. `GET /api/rms/calendar` becomes `?from&to&property_id` and returns the grid + counts. Keep the old departure query parameters working through the adapter until the panel switches (17-07); mark deprecated.
4. Departure KPIs (`kpis()`) stay for the yacht pages; add `NightAvailability::kpis(from, to)`: occupancy %, free room-nights, nights fully sold, nights below low-availability threshold (engine setting `availability.low_availability_threshold` — add it via config migration, default copied from the most common `departures.urgency_threshold` value at migration time, `approval_reference` `Sprint 17: availability.low_availability_threshold added (09 H7)`).

## Tests
- Grid states for each claim kind; expired hold shows FREE.
- Counts match a brute-force recomputation over the fixture.
- `canBook` on fragmentation case returns `NO_SINGLE_ROOM`.
- Query count is constant in the number of nights (assert with `DB::listen`).

## Done when
`GET /api/rms/calendar?from=…&to=…` answers for 31 nights × 24 rooms in < 300 ms locally (record the number in REPORT).
