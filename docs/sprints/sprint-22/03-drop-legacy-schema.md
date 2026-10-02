# 22-03 — Drop departures, itineraries, cabin claims and legacy columns

**Repo:** iconic-api
**Depends on:** 22-01, 22-02, and every other task of sprints 16–21 merged
**Read first:** 09 H19; `laravel.mdc` "Never edit a migration that has been merged"; all REPORTs for deprecated items

## Why
Contract phase. Two models side by side is a permanent source of bugs.

## Build
1. Pre-flight command `iconic:hotel-contract-check` (dry run, read-only) that fails if:
   - any `bookings` row has null `check_in`/`property_id`/`room_type_id`,
   - any active `cabin_claims` row has no matching active `room_night_claims` group (`legacy_cabin_claim_id`),
   - any code path still reads the columns below (static check: grep in `app/`),
   - any `waitlist_entries`/`offers`/`internal_blocks` row lacks its stay columns.
   Run it in CI for this task and paste its output into REPORT.
2. **Archive, then drop** (history keeps pointing at morph types, so keep the rows reachable):
   - `departures` → rename to `archive_departures`; `itineraries` → `archive_itineraries`; `cabin_claims` → `archive_cabin_claims` (keep the delete trigger); `manifests` stays (readable history) but its FK to departures is dropped. Read-only Eloquent models under `App\Models\Archive\` for the history viewer only.
   - Drop columns: `bookings.departure_id`, `bookings.back_to_back`, `bookings.png_collected`, `rooms.category`, `waitlist_entries.departure_id`, `waitlist_entries.cabin_category`, `offers` departure scoping, `guests.png_category` (if present), `room_night_claims.legacy_cabin_claim_id` (after the pre-flight passes).
   - Reference sequence `DEP` retired (row kept, never drawn).
3. Morph map / history: history rows whose `subject_type` is `Departure`/`Itinerary` resolve to the archive models (presentation only; rows untouched).
4. `down()` methods: restore table names and nullable columns (data in dropped columns is not restorable — say so in the migration docblock).

## Tests
Full `composer check` on a fresh DB and on a DB migrated from a Sprint 15 snapshot with the yacht fixture (both must pass); history page renders an archived departure's entries.

## Done when
`SHOW TABLES` has no `departures`, `itineraries` or `cabin_claims`; the pre-flight command passes.
