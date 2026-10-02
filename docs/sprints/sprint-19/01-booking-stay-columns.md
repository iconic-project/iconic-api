# 19-01 — Stay columns on bookings, backfill

**Repo:** iconic-api
**Depends on:** Sprints 16–18
**Read first:** 09 H1, H3, H8, H9, H11, §4; migrations for `bookings` (`…200022`, `…200026`, `…200038`, `…200039`, `…200060`, `…200071`); `Models/Booking.php`

## Build
1. Migration — add to `bookings`: `property_id` (FK), `room_type_id` (FK), `check_in` (date), `check_out` (date), `nights` (unsigned smallint), `rate_plan_code` (string 16, nullable), `night_lines` (json, nullable), `tax_lines` (json, nullable), `child_ages` (json, nullable), `expected_arrival_time` (char 5, nullable), `checked_in_at`, `checked_out_at`, `no_show_at` (timestamps, nullable). Index `(property_id, check_in)`, `(property_id, check_out)`, `(status, check_in)`.
2. Backfill (chunked): `check_in = departure.date`, `check_out = departure.stayDates().checkOut()`, `nights`, `property_id = departure.property_id`, `room_type_id = room.room_type_id` (charter/buyout bookings without a room: the property's first type by `sort`, flagged in REPORT), `rate_plan_code = null` (legacy), `night_lines = null` (legacy bookings keep `price_lines` only — frozen at sale). Then make `property_id, check_in, check_out, nights` NOT NULL. `departure_id` becomes **nullable** (dropped in Sprint 22).
3. Status values: `ON_BOARD` → `IN_HOUSE`, `COMPLETED` → `CHECKED_OUT`, add `NO_SHOW` (09 H11). Migration rewrites `bookings.status`; history rows are **not** rewritten (append-only) — `ChangeHistory` presentation maps old values to new labels for display.
4. `Booking`: `stay(): StayDates`; casts; `room()`, `roomType()`, `property()`. `departure()` stays nullable for legacy reads. Replace `balanceDueDate()` and the hold/document helpers' `departure->date` with `stay()->checkIn()` (09 H10) — the behaviour for backfilled yacht bookings is identical by construction; prove it with a test comparing old vs new values on the yacht fixture.
5. `BookingStatus`: rename cases `OnBoard` → `InHouse`, `Completed` → `CheckedOut`; add `NoShow`; `holdsInventory()` true for `InHouse`; `isConfirmedOrLater()` includes `InHouse`, `CheckedOut`, `NoShow`.
6. Resources: `BookingResource` exposes `stay {check_in, check_out, nights}`, `room`, `room_type`, `rate_plan`, `night_lines`, `tax_lines`, times. Keep `departure` (nullable) until Sprint 22.

## Tests
Backfill equivalence (balance due, deposit due, cancellation band, hold rule unchanged for yacht bookings); enum mapping; resource shape snapshot updated.

## Done when
No booking row has a null `check_in`; `composer check` green.
