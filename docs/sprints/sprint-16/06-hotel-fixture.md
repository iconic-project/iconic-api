# 16-06 — Hotel fixture and seeders

**Repo:** iconic-api
**Depends on:** 16-03, 16-05
**Read first:** `laravel.mdc` "Tests" (fixtures come from JSON via seeders), `docs/requirements/examples/seed-data.json`, `database/seeders/*`, 09 §4

## Why
Every later task needs realistic hotel data and reference prices to test against. Hand-typed sample data is forbidden; the fixture is the single source.

## Build
1. `docs/requirements/examples/hotel-seed-data.json` with top-level keys:
   - `meta`: `{ "demo": true, "note": "Demo values for development and tests. Real values are client inputs (09 HQ*)." }`
   - `property`: one property ("Hotel Demo", code `HTL`), content filled.
   - `room_types`: four types — `STD` (Standard Double, base 2, max 2), `TWN` (Twin, base 2, max 2), `FAM` (Family, base 2, max 4, max_children 2), `STE` (Suite, base 2, max 3).
   - `rooms`: 24 rooms over 3 floors (`101`–`108`, `201`–`208`, `301`–`308`) mixed across types (10 STD, 6 TWN, 5 FAM, 3 STE).
   - `seasons`, `room_rates`, `occupancy`, `length_of_stay`, `supplements`, `rate_plans` in the rates v2 shape of 09 H8 (consumed in Sprint 18; keep them here so the fixture is complete).
   - `restrictions`: a few date ranges (a stop-sell week, a min-stay-3 weekend, a closed-to-arrival day).
   - `bookings`: ~30 stays across statuses, lengths 1–14 nights, arrivals on every weekday, one 3-room group with different dates, two overlapping-adjacent pairs (A checks out the day B checks in, same room).
   - `reference_quotes`: 8 hand-computed quotes `{input, expected_lines, expected_total}` — the replacement for the doc-02 reference prices. Compute them by hand from the rules in 09 H8 and show the arithmetic in a `working` string per quote.
2. `HotelSeeder` (local/testing only, like the demo users): reads the fixture, creates property, room types, rooms. Bookings/claims seeding waits for Sprints 17/19 — leave a `// TODO(Sprint 19)` where they will go.
3. `DatabaseSeeder`: env flag `ICONIC_SEED_MODE=yacht|hotel` (default `yacht` in this sprint, switched to `hotel` in Sprint 19). Document it in `README.md`.
4. A Pest helper `hotelFixture(string $path)` mirroring the existing fixture helper.

## Tests
- Fixture schema test: occupancy limits consistent, every room's type exists, seasons do not overlap, every `reference_quotes[*].working` sums to `expected_total`.
- `HotelSeeder` is idempotent (run twice → same counts).

## Out of scope
Any change to the yacht fixture.

## Done when
`ICONIC_SEED_MODE=hotel php artisan migrate:fresh --seed` (in Docker) produces 1 property, 4 room types, 24 rooms.
