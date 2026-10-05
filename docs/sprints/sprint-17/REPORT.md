# Sprint 17 · Report

Each task appends its section below.

## Task 01 · Room-night claims table and backfill

### What was built

`room_night_claims` is the night ledger next to `cabin_claims`. One row is one room on one night. `active_key` is a stored generated column, `if(released_at is null, concat(room_id,'-',night), null)`, and it is unique, so a second active claim on the same room-night is refused by MySQL. A released row has a null key and can sit beside a new active row. `room_night_claims_prevent_delete` refuses deletes. `RoomNightClaim::delete()` throws as well.

`claim_group` is a uuid shared by every night of one stay claim. The backfill writes one group per `cabin_claims` row. `legacy_cabin_claim_id` is stored on every night of that source. A second run skips a source that already has any night row. The insert is chunked by 500 cabin claims. Copied fields are holder, kind, hold type, expiry, release, and the audit columns. Nights come from `departure->stayDates()`.

`RoomNightClaim` casts `night` with `CalendarDate`. On an existing row, `save()` and `update()` may change only `released_at`, `release_reason`, `updated_at`, and `updated_by`. Any other dirty column throws.

The migration backfill runs when the table is empty on a fresh database, because seeders insert cabin claims afterwards. `DatabaseSeeder` runs the same backfill after the yacht demo seeders, so a seeded database still gets one night row per claim night.

### Files touched

- `database/migrations/2026_10_03_110001_create_room_night_claims_table.php`
- `database/migrations/2026_10_03_110002_backfill_room_night_claims.php`
- `app/Models/RoomNightClaim.php`
- `app/Support/Inventory/BackfillRoomNightClaims.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/Inventory/RoomNightClaimsTest.php`
- `tests/Feature/Database/DatabaseSetupTest.php`
- `docs/sprints/sprint-17/REPORT.md`

### Deviations

The insert-refusing trigger on `cabin_claims` is not in this task. It lands in 17-03, together with the removal of the cabin-claim write path. Adding it now would make `ClaimService` and the yacht seeders fail, and `composer check` would not be green. 17-03 step 7 already removes that write path and should create the trigger in the same change. Until then, yacht code still inserts into `cabin_claims`.

`legacy_cabin_claim_id` is not unique by itself. One cabin claim becomes many night rows, and the done-when check counts those rows by the source id, so the id has to sit on every night. The unique key is `(legacy_cabin_claim_id, night)`. That still refuses a second copy of the same source night, and a re-run skips the source when any of its nights exist. MySQL treats nulls as distinct, so later rows with a null legacy id are not blocked by this index. `active_key` remains the double-sell guard.

`DatabaseSeeder` calls the backfill after the yacht demo seeders. The task only names the migration. The migration runs first, while `cabin_claims` is still empty, so without the seeder call a seeded database would have cabin claims and no night rows.

### Open questions

None.

### Notes for later

17-03 must add `cabin_claims_prevent_insert` (or the same `SIGNAL SQLSTATE '45000'`) when it deletes the cabin-claim write path. Do not add that trigger before the writers are gone.

17-03's `claim()` must write one `claim_group` across every night of the stay. Release and convert should move a group together. `legacy_cabin_claim_id` stays null on those new rows.

No HINV browser scenario in this task. The sprint list (HINV-01 … HINV-06) belongs to the later tasks. Yacht inventory screens are unchanged.

### Checks

`RoomNightClaimsTest` passed (5 tests, including the yacht-fixture backfill). `DatabaseSetupTest` and `HotelFixtureTest` passed inside the full suite. Pint is clean. PHPStan level 6 is clean with `--memory-limit=1G`. `tests/Arch` passed on its own (13 tests).

`php -d memory_limit=1G artisan test` still dies at 512MB inside `ArchTest` after the feature and concurrency runs, the same limit recorded in the sprint 16 report. The three failures in that run are the same three, and they are not caused by this task:

- `DemoAgenciesSeederTest` expects 3 agencies and sees 4.
- `EngineSettingsSeederTest` expects `Anakata` in `details_note` and the document says `Iconic`.
- `SensitiveEncryptedCastTest` expects `DecryptException` and gets `RuntimeException`.

## Task 02 · RoomAllocator

### What was built

`RoomAllocator` picks active rooms of a type that are free on every night of a stay. It does not write and it does not lock. A room is busy when a `room_night_claims` row for that night has `released_at` null and has not expired (`expires_at` null or `expires_at >= now()`). Expired holds and released rows leave the room free. Inactive rooms are ignored.

Among the rooms free for the whole stay, the lower score wins: `0` when the night before check-in and the check-out night are both already claimed, `1` when only one of those nights is claimed, `2` otherwise. Ties go to `sort`, then `id`. `pick` returns that many rooms, or throws `RoomUnavailableException` (HTTP 409). The message is `{type name} is unavailable on {night}.` The night is the first night where fewer than the requested count of rooms are still free from check-in through that night.

`explain` returns one `{night, free}` row per night of the stay, counting active rooms of the type with no blocking claim that night.

### Files touched

- `app/Services/Inventory/RoomAllocator.php`
- `app/Exceptions/RoomUnavailableException.php`
- `tests/Feature/Inventory/RoomAllocatorTest.php`
- `docs/sprints/sprint-17/REPORT.md`

### Deviations

The tests sit in `tests/Feature`, not `tests/Unit`. Unit tests in this repo do not boot Laravel or touch the database, and the allocator reads `room_night_claims`.

The 409 body is `{ "message" }` only. `CabinUnavailableException` also returns an `unavailable` list of cabins. This exception names the room type and the night instead, which is what this task asks for. No endpoint throws it yet, so there is no OpenAPI extension.

### Open questions

None.

### Notes for later

17-03 calls `pick` after the room locks and turns a unique `active_key` violation into this same exception. `explain` is there for that error path and for the panel calendar.

### Checks

`RoomAllocatorTest` passed (11 tests, 131 assertions), including gap filling (101 over 102), fragmentation, inactive rooms, expired holds, released claims, exclude, and 100 identical picks after a shuffled insert. Pint is clean. PHPStan level 6 is clean. pcov reports 100% line coverage on `RoomAllocator`. The image has no Xdebug path driver, so branch coverage is the exercised arms: score 0, score 1 on each side, score 2, sort tie-break, id tie-break, and each step of the shortfall walk.

## Task 03 · ClaimService on nights

### What was built

`ClaimService` now writes `room_night_claims`. `claim(StayDates, rooms, holder, kind, hold type, expiry)` locks those rooms in id order, releases expired holds on the same rooms and nights, then inserts one `claim_group` and one row per room per night. `claimType` locks every active room of the type, asks `RoomAllocator` to pick, then inserts the same way. `release` can limit the rooms and the nights. `convert` moves the active nights onto the new holder and returns the number of rooms moved (one per claim group and room), so a cabin count still matches. `releaseExpired` batches 500 claim groups and writes one `hold.expired` history row and one in-transaction `HoldExpired` per group, not per night. The request stays REQUESTED.

Claimability uses `StayClock::today()` and the published stay rules (or `BusinessRulesDocument::initial()` when nothing is published). Arrival cannot be in the past. Nights must sit between `min_nights` and `max_nights` unless the holder is an `InternalBlock`. Check-in must fall inside `booking_horizon_days`. A HOLD needs a future expiry and a hold type. Other kinds must have neither. Restriction checks are not here.

A unique `room_night_claims_active_key_unique` violation becomes `RoomUnavailableException` (HTTP 409). `AvailabilityChanged` now carries `property_id` and a `StayDates`. `BumpEngineFeedVersion` still bumps, and it forgets the cabin cache for departures that overlap the stay.

`LegacyDepartureClaims` is the yacht adapter. It locks the departure, calls `claim` with `$departure->stayDates()`, and turns `RoomUnavailableException` back into `CabinUnavailableException` with the old cabin payload. Reservation, request, move, transition, internal block, checkout create, and the demo seeders go through the adapter. Release-only callers and `SubmitEngineCheckout` still call `ClaimService` directly.

`cabin_claims_prevent_insert` refuses new cabin-claim rows (`SIGNAL SQLSTATE '45000'`). Readers that still speak departures and cabins now read night rows through `DepartureNightClaims`: availability, departure date and delete locks, commercial metrics (distinct rooms), holds, internal blocks, and the future-claim guard.

### Files touched

- `app/Services/Inventory/ClaimService.php`
- `app/Services/Inventory/LegacyDepartureClaims.php`
- `app/Support/Inventory/RoomLocks.php`
- `app/Support/Inventory/DepartureNightClaims.php`
- `database/migrations/2026_10_03_120001_refuse_cabin_claim_inserts.php`
- `app/Events/AvailabilityChanged.php`, `app/Events/HoldExpired.php`
- `app/Listeners/BumpEngineFeedVersion.php`, `app/Listeners/OfferWaitlistCabins.php`
- `app/Services/Inventory/Availability.php`
- `app/Support/Inventory/DepartureLocks.php`
- `app/Support/Metrics/CommercialMetrics.php`
- `app/Support/Rooms/FutureClaimGuard.php`
- `app/Support/Bookings/RequestSummary.php`
- `app/Models/Booking.php`, `CheckoutSession.php`, `InternalBlock.php`
- `app/Http/Controllers/Rms/HoldController.php`, `InternalBlockController.php`
- `app/Http/Resources/Rms/InternalBlockResource.php`
- `app/Actions/Bookings/CreateReservation.php`, `CreateBookingRequest.php`, `MoveBooking.php`, `TransitionBooking.php`
- `app/Actions/Blocks/CreateInternalBlock.php`
- `app/Actions/Checkout/CreateCheckoutSession.php`, `ExtendCheckoutSession.php`
- `app/Console/Commands/ExpireHoldCommand.php`
- `database/seeders/DemoInventorySeeder.php`, `DemoBookingsSeeder.php`, `DemoAgenciesSeeder.php`
- `tests/Feature/Inventory/CabinClaimsTest.php`, `RoomNightClaimsTest.php`, and the booking, checkout, inventory, and concurrency tests that asserted cabin rows

### Deviations

`grep -rn "DepartureLocks::lock\|CabinClaim::create\|cabin_claims" app/` is not empty. `CabinClaim::create` and the string `cabin_claims` are gone from `app/`. `DepartureLocks::lock` remains outside the adapter, on purpose. Those locks serialise a departure date change with a sale, a checkout, or a booking mutation. Removing them would change yacht behaviour. They are still in `CreateReservation`, `CreateBookingRequest`, `CreateCheckoutSession`, `ExtendCheckoutSession`, `ReleaseCheckoutSession`, `SubmitEngineCheckout`, `FallBackOnlineDeposit`, `SubmitPortalRequest`, `AddWaitlistEntry`, `UpdateDeparture`, `DeleteDeparture`, and `BookingMutationLock::lockMany` (the grep matches the `lock` prefix). The adapter locks the departure as well, then locks rooms.

The insert trigger was deferred from 17-01. It lands here, in a new migration. The merged `cabin_claims` migration is unchanged.

`Availability`, departure locks, metrics, holds, and blocks read night claims so yacht screens still see sold, held, and blocked cabins. This task does not build `NightAvailability`. Metrics count `COUNT(DISTINCT room_id)` so a 7-night stay is still one berth. `convert` returns that room count for the same reason. Tests that used to count cabin rows now count distinct rooms.

`Departure::claims()` is still `cabin_claims`. Night rows have no `departure_id`. The historical relation stays for old rows.

An overlapping pair of yacht departures now shares the night ledger. `OccupancyCheckTest` moved the "outside the window" departure from 2020-04-05 to 2020-04-12 so it does not occupy the 2020-04-04 nights. `GuestPngPersistTest` freezes the clock at 2026-11-13 so the 2028-11-12 move stays inside the 730-day horizon.

### Open questions

None.

### Notes for later

17-04 builds `NightAvailability` and the calendar `from`/`to` read. Until then, departure snapshots are the bridge above.

Sprint 19 deletes `LegacyDepartureClaims` and the departure-row locks that exist only to keep yacht callers serialised. Do not strip those locks before the callers move to stays.

`convert` re-inserts the active night dates of each group. A gap left by a partial release stays a gap. It does not fill the span from the first night to the last.

### Checks

`CabinClaimsTest`, `RoomNightClaimsTest`, `ClaimServiceConcurrencyTest`, `ReleaseExpiredHoldsTest`, and `RequestHoldExpiryTest` passed, including partial release, `claimType`, past / max / horizon refusals, adjacent stays, overlap 409, and `HoldExpired` inside the same transaction.

The feature suite (feature plus concurrency) was 1251 passed and 4 failed. `GuestPngPersistTest` failed because 2028-11-12 is outside the horizon from the real clock. After the frozen clock, that file passed (2 tests). The other three failures are the same ones recorded on task 01, and they are not caused by this task:

- `DemoAgenciesSeederTest` expects 3 agencies and sees 4.
- `EngineSettingsSeederTest` expects `Anakata` in `details_note` and the document says `Iconic`.
- `SensitiveEncryptedCastTest` expects `DecryptException` and gets `RuntimeException`.

Unit tests passed (150). `tests/Arch` passed on its own (13). Pint is clean. PHPStan level 6 is clean with `--memory-limit=1G`. `php -d memory_limit=1G artisan test` still dies at 512MB inside `ArchTest` when that process also runs the feature suite. No e2e scenario changed. `BKG-10` still expires holds through `ClaimService::releaseExpired()`.

## Task 04 · Availability by night

### What was built

`NightAvailability` answers what is free on each night of a property. `grid` covers `[from, to)` for every active room. Each cell is `FREE`, `HELD`, `SOLD`, or `BLOCKED` (`RoomNightState`) plus a claim summary: `claim_group`, holder reference, guest surname, and owner. The surname is the lead guest's last name, or the first named guest. The owner is the booking owner's name. An expired hold (kind `HOLD`, `expires_at` set, `expires_at` before now) is `FREE` and has no claim. The range is at most 62 nights.

`countsByType` returns, per night and room-type code, `total`, `free`, `held`, `sold`, and `blocked`. `canBook` uses `RoomAllocator::explain`. If any night has fewer free rooms than requested, the reason is `SOLD_OUT`. If every night has enough free rooms but `pick` throws `RoomUnavailableException`, the reason is `NO_SINGLE_ROOM`. Restrictions are not checked.

Occupancy (the out-of-order definition): `room_nights_available` is free plus held plus sold. Blocked room-nights are excluded. `room_nights_sold` is sold. `pct` is `intdiv(sold * 100, available)`, or 0 when available is 0. Held is in the denominator and is not sold. Inactive rooms are excluded from the grid, the counts, and occupancy.

`kpis` returns that occupancy percent, free room-nights, nights fully sold, and nights below the low-availability threshold. A night is fully sold when free is 0, held is 0, and sold is greater than 0. A night is below the threshold when free is greater than 0 and free is less than or equal to the threshold. Free is the count of free rooms that night across types. The threshold is the published engine setting `availability.low_availability_threshold`. When nothing is published, it is the `initial()` value, 3.

`GET /api/rms/calendar` with `property_id` returns the night grid, counts, occupancy, and kpis. The same path without `property_id` still returns the departure calendar. The new setting ships in a DML migration. On a database that already has engine settings and no threshold, it republishes the most common `departures.urgency_threshold`, or 3 when that table is empty. The approval reference is `Sprint 17: availability.low_availability_threshold added (09 H7)`.

### Files touched

- `app/Services/Inventory/NightAvailability.php`, `NightGrid.php`, `Bookability.php`, `LegacyDepartureCalendar.php`
- `app/Enums/RoomNightState.php`, `BookabilityReason.php`, `CabinState.php`
- `app/Support/Config/Documents/AvailabilitySettings.php`, `EngineSettingsDocument.php`
- `app/Http/Controllers/Rms/CalendarController.php`
- `app/Http/Requests/Rms/IndexCalendarRequest.php`
- `app/Http/Resources/Rms/NightCalendarResource.php`, `CalendarGridResource.php`
- `database/migrations/2026_10_05_100001_add_availability_threshold_to_engine_settings.php`
- `tests/Feature/Inventory/NightAvailabilityTest.php`
- `docs/sprints/sprint-17/REPORT.md`

### Deviations

The night grid is returned only when `property_id` is present. Without it, the departure calendar stays: inclusive dates, 18-month maximum, `departures` and `rows`. The panel still sends only `from` and `to`, and its default window is six months, which is more than 62 nights. Switching on range length would break `AvailabilityEndpointsTest`, `InternalBlocksTest`, and `RequestCalendarQueryCountTest`. The 31-night timing below uses `?from&to&property_id`.

`CabinState` stays a deprecated twin with the same four values. PHP enums cannot share a case list in a way PHPStan accepts, so there is no class alias. Yacht callers still use `CabinState`.

`kpis` takes a `Property` first, like the other methods. The HTTP night payload also includes occupancy and kpis. The claim query eager-loads the holder, the booking owner, and guests, so the query count is constant in the number of nights and is not exactly two queries.

### Open questions

None.

### Notes for later

17-06 adds restriction checks to `canBook`. 17-07 must send `property_id` and a window of at most 62 nights. The panel engine-settings form does not edit `availability.low_availability_threshold`, but it clones the whole document, so a publish keeps the key. Sprint 20's engine calendar uses `countsByType`.

### Checks

`NightAvailabilityTest` passed (10 tests): each claim kind, an expired hold as `FREE`, counts matching a brute-force recount, occupancy 50 with one blocked room-night excluded, `NO_SINGLE_ROOM` on the fragmented two-room stay, `SOLD_OUT` when the last night has no free room, KPIs, a query count that does not grow from 1 night to 31 nights, the calendar rejecting 63 nights, and the departure calendar still returning `departures` and `rows` when `property_id` is absent. The settings migration copies the modal urgency threshold (4) and keeps `initial()` at 3.

`GET /api/rms/calendar?from=2028-06-01&to=2028-07-02&property_id=…` for 31 nights and 24 rooms took 34 ms (33.9 ms around the HTTP call), under the 300 ms limit.

`AvailabilityEndpointsTest`, `InternalBlocksTest`, `RequestCalendarQueryCountTest`, and `EngineSettingsDocumentTest` passed. `EngineSettingsSeederTest` still fails only on the pre-existing `details_note` (`Anakata` expected, `Iconic` stored). Pint is clean on the files above. PHPStan level 6 is clean on those files with `--memory-limit=1G`. No e2e scenario changed. `INV-01` still describes the departure calendar, which this response still serves until 17-07.

## Task 05 · Internal blocks over date ranges

### What was built

An internal block is one property and a half-open stay `[starts_on, ends_on)`. `CreateInternalBlock` takes `starts_on`, `ends_on`, a reason, optional notes, and either a list of room ids or a room type plus a count. It writes the block, then `ClaimService::claim` or `claimType` with `ClaimKind::Block`. Blocks still skip the min and max stay checks. They still cannot start in the past, and check-in still has to fall inside the booking horizon. Restrictions are not checked.

`POST /api/rms/blocks/{block}/release` still stores `release_note`. The note is now required. `POST /api/rms/blocks/{block}/shorten` moves either the start later or the end earlier, releases the nights that fall off, and writes one `block.shortened` history row with the old range, the new range, and a mandatory reason. Both edges at once, a hole in the middle, and an extension are refused.

The list and the block payload return the property, `starts_on`, `ends_on`, the night count, the rooms, and `scope_summary`. Example: `Rooms 101, 102 · Fri 3 – Mon 6 Mar 2028 · 3 nights`. There is no departure on the payload. A date filter overlaps the block range. `property_id` filters the block's property.

A conflict is the earliest still-held night on the rooms being blocked, then the lowest room sort. The sentence is `Room 204 is sold on Sat 4 Mar 2028 (ANK-2028-0012)`. An expired hold does not count. `BlockReason` is unchanged: `FAM_TRIP`, `MAINTENANCE`, `NEGOTIATION_HOLD`, `COURTESY`.

The migration adds `property_id`, `starts_on`, and `ends_on`, then backfills them from the block's night claims. `ends_on` is the day after the last claimed night. The property is the property of the earliest night. The demo block `BLK-001` is created through `CreateInternalBlock` for the nights of `DEP-003`.

### Files touched

- `database/migrations/2026_10_05_110001_add_stay_range_to_internal_blocks.php`
- `app/Support/Inventory/BackfillInternalBlockRanges.php`
- `app/Models/InternalBlock.php`
- `app/Actions/Blocks/CreateInternalBlock.php`, `ShortenInternalBlock.php`, `ReleaseInternalBlock.php`
- `app/Support/Blocks/ScopeSummary.php`, `ConflictMessage.php`
- `app/Http/Controllers/Rms/InternalBlockController.php`
- `app/Http/Requests/Rms/StoreInternalBlockRequest.php`, `ShortenInternalBlockRequest.php`, `ReleaseInternalBlockRequest.php`
- `app/Http/Resources/Rms/InternalBlockResource.php`
- `app/Policies/InternalBlockPolicy.php`
- `routes/api/rms.php`
- `app/Actions/Bookings/CreateReservation.php`, `CreateBookingRequest.php`, `MoveBooking.php` (conflict sentence no longer takes a departure)
- `database/seeders/DemoInventorySeeder.php`
- `tests/Feature/Inventory/InternalBlocksTest.php`, `DemoInventorySeederTest.php`
- `tests/Unit/Support/Blocks/ScopeSummaryTest.php`
- `tests/Feature/Bookings/CreateReservationTest.php`, `QuoteReservationTest.php`
- `tests/e2e/scenarios/inventory/INV-08-block-see-release.md`, `INV-09-block-conflict.md`
- `docs/sprints/sprint-17/REPORT.md`

### Deviations

09's schema line says `internal_blocks + room_id`. A block covers many rooms, so the rooms stay on `room_night_claims`. The block row stores `property_id`, `starts_on`, and `ends_on`.

`OUT_OF_ORDER` was not added. 09 was not amended, so maintenance stays `MAINTENANCE`.

Shortened nights are released with `ReleaseReason::Released`. No new release reason.

A conflict response is still `CabinUnavailableException`, so the existing 409 schema stays. The message is the new sentence and `unavailable` lists that one night. When no holder is found (the type simply has fewer rooms than `count`), the message is the allocator's sentence and `unavailable` is empty.

The task's sample weekday "Wed 4 Mar 2028" is a Saturday. The formatter uses the real weekday.

`DemoInventorySeeder` still reads `DEP-003` to choose the demo nights. The block actions, the block model, the block HTTP layer, and `Support/Blocks` do not reference `Departure`.

### Open questions

None.

### Notes for later

17-07 must post `starts_on`, `ends_on`, and rooms or type plus count, and it must send a release note. The current panel still posts departures and may send a null note, so release and create on that page return 422 until then. `INV-08` and `INV-09` expected copy matches the new sentences. Their create steps still describe the departure form.

A block that used to span two properties backfills as one property (the earliest night) and one span from the first claimed night to the day after the last. The nights in a gap stay inside that span on the block row. The claim rows are unchanged.

### Checks

`InternalBlocksTest` passed (14 tests): conflict sentence, expired hold ignored, create by rooms, create by type and count, a 40-night block, past and mixed-property refusals, mandatory release note, shorten leading and trailing, one history row per change, admin and manager write, sales exec read-only, departure calendar still shows `BLOCKED` then `FREE`, departure delete still refused, backfill restores the claim span.

`ScopeSummaryTest` passed (4). `DemoInventorySeederTest`, `CreateReservationTest`, and `QuoteReservationTest` passed. Pint is clean. PHPStan level 6 is clean on the files above with `--memory-limit=1G`.

## Task 06 · Restrictions

### What was built

`stay_restrictions` stores one row per property, room type, and night. `room_type_id` null means every type. MySQL treats those nulls as distinct, so `scope_key` is a stored generated column, `concat(property_id, '-', ifnull(room_type_id, 0), '-', night)`, and it is unique. Night uses `CalendarDate`.

`SetStayRestrictions` is the only writer. `from` and `to` are inclusive. An empty `room_type_ids` list writes one property-wide row per night. A missing field is left as stored. A row that ends as all defaults (bools false, min, max, and note empty) is deleted. One `restrictions.set` history row is written on the property per call, including a weekday filter that matches nothing. Weekdays are ISO, Monday 1 through Sunday 7. The actor is the user, or System when the caller passes none.

`Restrictions::evaluate` uses whole-row precedence. A type row beats a property-wide row for that night. A null min or max on the winning row uses the business-rule default (`stay.min_nights` 1, `stay.max_nights` 30), read through `CurrentConfig`, or `BusinessRulesDocument::initial()` when rules are unpublished. It does not inherit the losing row. `stop_sell` is any stay night. `closed_to_arrival` is the check-in night. `closed_to_departure` is the check-out date, which is not a stay night. Min and max are read from the arrival night only. Every failure is returned. Codes are `STOP_SELL`, `CLOSED_TO_ARRIVAL`, `CLOSED_TO_DEPARTURE`, `MIN_STAY:n`, `MAX_STAY:n`. `n` is the threshold.

`NightAvailability::canBook` adds those reasons and the inventory reason. `Bookability` now carries `reasons` as a list of strings. `ok` is false when any reason is present. The order, also on `StayRestrictionResource::REASON_ORDER` and on `GET /api/rms/restrictions` as `reason_order`, is `STOP_SELL`, `CLOSED_TO_ARRIVAL`, `CLOSED_TO_DEPARTURE`, `MIN_STAY:n`, `MAX_STAY:n`, `SOLD_OUT`, `NO_SINGLE_ROOM`. A short night is `SOLD_OUT` only. `NO_SINGLE_ROOM` is used when every night has enough free rooms and no single room covers the stay. Inventory is still computed when a sell rule fails.

`GET /api/rms/restrictions` needs `panel.rms`. `PUT /api/rms/restrictions` needs `inventory.manage_restrictions`. The payload labels the range `inclusive`.

`bookings.override_restrictions` lets staff pass `override_restrictions` and a mandatory `restriction_reason` on create reservation, create request, and move. The reason is the booking history reason. The codes sit on the history `after` payload. No failing rule leaves the history reason null. Missing permission is 403. A blank reason is 422. Without the flag, a restricted stay is 422 and the codes are the `stay` errors. Sales exec has neither new permission. Manager gets both from `SystemRole` defaults, and the migration appends them onto an existing manager role. Admin already holds every permission through `isAdmin()`.

The backfill calls `SetStayRestrictions` once per `CLOSED` or `HIDDEN` departure: property-wide `stop_sell` from check-in through the last night. `ON_SALE` and `CHARTER` are skipped. A second run does not add rows. `DatabaseSeeder` runs it after the yacht demo seed, because migrate runs while `departures` is still empty.

### Files touched

- `database/migrations/2026_10_05_120001_create_stay_restrictions_table.php`
- `database/seeders/DatabaseSeeder.php`
- `app/Models/StayRestriction.php`
- `app/Actions/Restrictions/SetStayRestrictions.php`
- `app/Services/Inventory/Restrictions.php`
- `app/Services/Inventory/RestrictionResult.php`
- `app/Services/Inventory/Bookability.php`
- `app/Services/Inventory/NightAvailability.php`
- `app/Support/Inventory/BackfillClosedDepartureRestrictions.php`
- `app/Support/Inventory/StaffStayRestrictions.php`
- `app/Support/Inventory/AppliedRestrictionOverride.php`
- `app/Http/Controllers/Rms/RestrictionController.php`
- `app/Http/Requests/Rms/IndexRestrictionsRequest.php`
- `app/Http/Requests/Rms/SetStayRestrictionsRequest.php`
- `app/Http/Requests/Rms/StoreReservationRequest.php`
- `app/Http/Requests/Rms/MoveBookingRequest.php`
- `app/Http/Resources/Rms/StayRestrictionResource.php`
- `app/Policies/StayRestrictionPolicy.php`
- `app/Enums/Permission.php`
- `app/Enums/SystemRole.php`
- `app/Actions/Bookings/CreateReservation.php`
- `app/Actions/Bookings/CreateBookingRequest.php`
- `app/Actions/Bookings/MoveBooking.php`
- `routes/api/rms.php`
- `tests/Feature/Inventory/RestrictionsTest.php`
- `tests/Feature/Inventory/NightAvailabilityTest.php`

### Deviations

Precedence is the whole winning row. A null min or max on that row uses the business-rule default. It does not copy the property-wide value the type row replaced. A false bool on the type row overrides a true bool on the property row.

There is no range cap. `to` must be on or after `from`. Both dates are inclusive, and the GET and PUT payloads say `range: inclusive`.

`Bookability::$reason` is now `$reasons`, a list of strings, so `MIN_STAY:n` can sit beside `SOLD_OUT`. The two inventory codes stay on `BookabilityReason`.

Deleting an all-default restriction row is required. There is no delete trigger on `stay_restrictions`.

Move preview still answers from inventory only. The move itself applies the sell rules.

### Open questions

None.

### Notes for later

17-07 is the restrictions screen. This task does not change the panel.

Engine and portal do not call `canBook`. `stop_sell` does not block them until the sprint 20 calendar uses `evaluate`. Staff create, request, and move are the paths that enforce it now.

A later change to a departure's status does not rewrite `stay_restrictions`. The backfill is one-shot.

### Checks

`RestrictionsTest` passed (16 tests): type row beats property row, null min uses the business rule, closed-to-departure is the check-out date, min and closed-to-arrival are the arrival night, every code in order, a 7-night stay with no row is clear, a 31-night stay is `MAX_STAY:30`, inclusive range, weekday filter, history once even when no night matches, clearing deletes the row, partial update keeps the other fields, empty type list is property-wide, min above max is 422, `canBook` returns `STOP_SELL` then `SOLD_OUT`, a stopped type blocks a mixed stay, sales can read and cannot write, manager and admin can write, closed and hidden backfill through the last night and a second run stays at 14 rows, charter and on-sale do not, create and request and move are 422 unless a manager sends a reason, sales override is 403.

`NightAvailabilityTest`, `CreateReservationTest`, `CreateBookingRequestTest`, `MoveBookingTest`, `PermissionTest`, and `PermissionCatalogueTest` passed in the same run (55 tests). Pint is clean. PHPStan level 6 is clean on the files above with `--memory-limit=1G`.

## 07 — Panel calendar

### What was built

The reservations calendar is a room-night timeline. Rooms group by room type, and each type collapses. The window is 14 or 31 nights, with previous, next, and today. The window scrolls inside the page. A bar is one `claim_group`. Sold, held, and blocked use the existing swatches. The label is the reference and the surname. Header rows show occupancy for each night and free rooms of each type. Window KPIs come from the API (`occupancy_pct`, `free_room_nights`, `nights_fully_sold`, `nights_below_threshold`).

A booking bar opens a read-only drawer. A block bar opens the block drawer. Dragging free cells on one room, when the user has `blocks.manage`, opens New block for that room and the stay `[first night, day after last night)`.

Yacht layout stays as a route and redirects to the calendar. It is gone from the menu. Inventory → Restrictions is new. The month grid shows the winning row per type per night (stop sell, closed to arrival, closed to departure, min–max). The bulk editor sends the PUT body: inclusive dates, weekdays only when the set is not the whole week, an empty type list for a property-wide row, and only the fields that were set. Without `inventory.manage_restrictions` the page is read-only.

Blocks are created with `AnkStayInput` plus rooms, or a room type and a count. The list shows the stay, the night count, and the scope. Shorten moves one edge and requires a reason. Release requires a note.

The departures page stays, with the banner “Departures are retired in Sprint 22 — use Restrictions”.

### Files

API: `NightAvailability.php`, `NightGrid.php`, `NightAvailabilityTest.php` (claim summary now includes `holder_type` and `holder_id`).

Types: `iconic-ui/app/types/inventory.ts`, `iconic-ui/app/types/index.ts`, `iconic-ui/app/types/api.d.ts`, `iconic-panel/app/types/api.ts`.

Panel: `app/pages/rms/reservations/calendar.vue`, `app/pages/rms/reservations/yacht-layout.vue`, `app/pages/rms/operations/blocks.vue`, `app/pages/rms/inventory/restrictions.vue`, `app/pages/rms/booking-engine/departures.vue`, `app/navigation/rms.ts`, `app/components/calendar/NightTimeline.vue`, `app/components/calendar/NightBookingDrawer.vue`, `app/components/calendar/nightCalendar.ts`, `app/components/restrictions/restrictionEditor.ts`, `app/components/blocks/NewBlockModal.vue`, `app/components/blocks/ShortenBlockModal.vue`, `app/components/blocks/BlockDrawer.vue`, `app/components/blocks/ReleaseBlockModal.vue`, `app/components/blocks/blockHelpers.ts`, `app/assets/css/inventory.css`, `eslint.config.mjs`, `i18n/locales/en.json`, `i18n/locales/es.json`, `tests/unit/nightCalendar.test.ts`, `tests/unit/restrictionEditor.test.ts`.

Scenarios: a status line on INV-01, INV-07, INV-08, INV-09, and INV-12. The steps still describe the departure grid. Task 08 rewrites them.

### Deviations

`pnpm types:api` was not run. `NightCalendar` is a TypeScript mirror of `NightGrid`. The generated `Permission` union was given `inventory.manage_restrictions` and `bookings.override_restrictions` by hand so `can()` typechecks. The generated `InternalBlockResource` still has `claims`, so the panel uses a `RangeBlock` mirror of the current resource.

Occupancy for one night is not on the payload. The panel uses the API formula on `counts`: `floor(sold * 100 / (free + held + sold))`, and blocked rooms are left out. The window KPIs are the API figures, including the threshold count.

`AnkStayInput` reads `stay.min_nights`, `stay.max_nights`, and `stay.booking_horizon_days` from the published business rules. If those numbers are missing, the control is not shown and nothing is hardcoded. Shorten uses date fields, not `AnkStayInput`, so a shorten is not blocked by the published minimum stay. The API accepts any shorter range that moves exactly one edge.

The booking drawer does not use the editable booking panel.

### Open questions

None.

### Notes for later

Regenerate OpenAPI (`pnpm types:api`) and drop the `NightCalendar` mirror, the `RangeBlock` mirror, and the hand-edited permission literals.

Panel `nuxt typecheck` still fails on older screens that read `yacht` from types that now expose `property` (`calendarHelpers.ts`, departures, holds, manifests, guest experience, reports, the dashboard, and a few drawers). Those errors were already in the tree. The files from this task are not in that list.

The running database has ANAMARA and ANATIVA (18 rooms), not a separate hotel seed. The embedded browser loaded the login page and could not reach the API on port 18000 (`Failed to fetch` on `/sanctum/csrf-cookie`), so the signed-in calendar, block form, and restrictions grid were not clicked through. The panel dev server was left at `http://127.0.0.1:3001/` with `NUXT_PUBLIC_API_BASE=http://localhost:18000`.

Booking scenarios that still look for Year 2027 departure columns (BKG, PAY, WEB, DOC, PREQ, VIS) belong to task 08, with the inventory scenarios above.

### Checks

`NightAvailabilityTest` passed (10 tests), including `holder_type` and `holder_id`. Pint and PHPStan are clean on `NightAvailability.php` and `NightGrid.php`.

Panel unit tests: `nightCalendar`, `restrictionEditor`, `blockHelpers`, and locale parity passed. The full Vitest run is 320 tests, all passing after the Spanish keys were added. ESLint is clean on the files this task touched.

## 08 — Sprint close: e2e and report

### What was built

Hotel scenarios HINV-01 through HINV-06. INDEX lists them as batch B22.

Retired: INV-01 (departure columns), INV-08 (block by departure), INV-09 (block conflict on a departure). Replaced by HINV-01, HINV-02, HINV-04, and HINV-03.

Updated: INV-10. Release of BLK-001 now requires a note. The departure-lock expectations stay. INV-10 remains P2.

The P1 set was not run. `tests/e2e/bin/up.sh` was not started. Its first step copies `tests/e2e/environment/api.env` over `iconic-api/.env`. Host ports 8000 and 8001 belong to `keevaris-api`. The working API containers already use the names `iconic-api`, `iconic-mysql`, `iconic-redis`, and `iconic-mailpit`, and mailpit already owns 8025. Port 3000 has a listener on `[::1]`. Port 3001 was the panel dev server from task 07; that process was stopped. Memory available was 10.6 GiB, above the 6 GiB warning. Detail: `tests/e2e/runs/2026-10-05-1535-sprint-17-p1.md`. Ledger: `tests/e2e/runs/LEDGER.md`. INDEX has 102 P1 rows. 0 passed, 0 failed, 102 not run.

### Sprint summary

`room_night_claims` is the inventory ledger. `RoomAllocator` picks a room. `ClaimService` claims a stay. Availability answers free rooms per type per night and whether a stay can be booked. Internal blocks and sell restrictions use date ranges. The panel calendar is rooms by night, with shorten, release, and a restrictions grid. Yacht claims are backfilled. The default seed is still yacht. Hotel Demo is `ICONIC_SEED_MODE=hotel`, and that seeder does not create bookings yet.

### Accepted reasons (P1 did not pass)

- **ENV.** The e2e stack cannot start beside `keevaris-api` and the working iconic containers without replacing `.env` and colliding on container names and ports 8000, 8001, and 8025.
- **Seed.** HINV-01 through HINV-06 require hotel seed. `tests/e2e/environment/api.env` does not set `ICONIC_SEED_MODE=hotel`. The default remains yacht until Sprint 19.
- **HINV-03 E2.** A sold night is not in the hotel seed. `HotelSeeder` leaves bookings for Sprint 19. New reservation still asks for a departure. The blocked-night sentence in E1 is the check that can run on this seed.

### Files touched

- `tests/e2e/scenarios/hotel/HINV-01-timeline-shows-the-hotel.md` (new)
- `tests/e2e/scenarios/hotel/HINV-02-block-three-nights.md` (new)
- `tests/e2e/scenarios/hotel/HINV-03-block-conflict-names-the-night.md` (new)
- `tests/e2e/scenarios/hotel/HINV-04-shorten-and-release.md` (new)
- `tests/e2e/scenarios/hotel/HINV-05-stop-sell-and-min-stay.md` (new)
- `tests/e2e/scenarios/hotel/HINV-06-lucia-sees-cannot-edit.md` (new)
- `tests/e2e/scenarios/inventory/INV-01-seeded-calendar.md` (retired)
- `tests/e2e/scenarios/inventory/INV-08-block-see-release.md` (retired)
- `tests/e2e/scenarios/inventory/INV-09-block-conflict.md` (retired)
- `tests/e2e/scenarios/inventory/INV-10-departure-locks.md` (release note)
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/2026-10-05-1535-sprint-17-p1.md` (new)
- `tests/e2e/runs/LEDGER.md`
- `docs/sprints/sprint-17/REPORT.md`

### Deviations

`up.sh` was not executed. Running it would replace the local `.env` and fight containers that are already up. No product code was changed in this task.

`composer check` was not re-run. This task did not change PHP.

### Open questions

None.

### Notes for later

- Bring the e2e stack up only when ports 8000, 8001, 8025, 3000, and 3001 are free and replacing `iconic-api/.env` is acceptable. Do not stop `keevaris-api` to free 8000.
- Set `ICONIC_SEED_MODE=hotel` before `reset.sh` when walking HINV. Sprint 19 is the switch of the default seed, and the hotel booking seed.
- Booking scenarios that still look for Year 2027 departure columns stay as they are. This task retired only INV-01, INV-08, and INV-09.
- Panel `nuxt typecheck` still fails on older `yacht` reads. See task 07.

### Git commands for the user

Nothing was committed. Tasks 01–07 are still uncommitted in their repos. Review `git status` in `iconic-api`, `iconic-ui`, and `iconic-panel` before adding those files. The commands below are only the sprint-close documents.

```bash
cd iconic-api
git add \
  docs/sprints/sprint-17/REPORT.md \
  tests/e2e/scenarios/INDEX.md \
  tests/e2e/scenarios/hotel/HINV-01-timeline-shows-the-hotel.md \
  tests/e2e/scenarios/hotel/HINV-02-block-three-nights.md \
  tests/e2e/scenarios/hotel/HINV-03-block-conflict-names-the-night.md \
  tests/e2e/scenarios/hotel/HINV-04-shorten-and-release.md \
  tests/e2e/scenarios/hotel/HINV-05-stop-sell-and-min-stay.md \
  tests/e2e/scenarios/hotel/HINV-06-lucia-sees-cannot-edit.md \
  tests/e2e/scenarios/inventory/INV-01-seeded-calendar.md \
  tests/e2e/scenarios/inventory/INV-08-block-see-release.md \
  tests/e2e/scenarios/inventory/INV-09-block-conflict.md \
  tests/e2e/scenarios/inventory/INV-10-departure-locks.md \
  tests/e2e/runs/LEDGER.md \
  tests/e2e/runs/2026-10-05-1535-sprint-17-p1.md
git commit -m "$(cat <<'EOF'
Record sprint 17 close and the night-inventory scenarios.

EOF
)"
```

