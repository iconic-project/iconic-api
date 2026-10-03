# Sprint 16 · Report
Each task appends its section below.

## Task 01 · Adopt the hotel decisions and amend the rules

### What was built
`09-hotel-generalisation.md` was already in `docs/requirements/`. It is now listed in `docs/requirements/INDEX.md`, and it ranks above `08` for stays, rooms and nights.

`iconic-core.mdc` now describes a hotel reservation system (rooms sold by the night). The order of authority puts `09` first, scoped as in that document's header. Rule 5 is the stay interval `[check_in, check_out)`. Rule 9 also forbids automation from changing a stay status (09 H11).

`laravel.mdc` names check-in, check-out, nights and date of birth as `CalendarDate` fields, says an expired hold frees room-nights, points tests at `hotel-seed-data.json` (`reference_quotes`) while keeping the yacht price list until Sprint 18, and quotes the §2 naming rule.

`README.md`, `AGENTS.md` and `CLAUDE.md` open with "Laravel 13 API for Iconic (hotel reservations)" and link `docs/sprints/HOTEL-ROADMAP.md`.

### Files touched
- `.cursor/rules/iconic-core.mdc`
- `.cursor/rules/laravel.mdc`
- `README.md`
- `AGENTS.md`
- `CLAUDE.md`
- `docs/requirements/INDEX.md` (restored, then the 09 line added)
- `docs/requirements/09-hotel-generalisation.md` (already present, not edited)
- `docs/sprints/sprint-16/REPORT.md`

### Deviations
`docs/requirements/INDEX.md` was not on disk. Commit `4e42d8c` deleted it with the rest of `docs/requirements/` (except the brand book). Restored the index from the parent of that commit, added the 09 line, and wrote Iconic where the old index said Anakata (`iconic.co`, "Iconic's decisions"). The later rename commit never saw this file.

`08` in the index is no longer "highest authority" without qualification. The 09 line would otherwise contradict it.

`AGENTS.md` and `CLAUDE.md` had no "Requirements and sprints" heading. Added one so the roadmap link has the place the task names.

### Open questions
None.

### Notes for later
Sibling copies of `iconic-core.mdc` in `iconic-ui`, `iconic-panel`, `iconic-engine` and `iconic-portal` still say departures are Sundays and still name yachts ANAMARA and ANATIVA. This workspace applies every copy. Sync them or agents in those repos still see the yacht rule. This task's repo line is iconic-api only.

`01`–`08`, the prototypes, the screenshots, `docs/requirements/README.md` and sprints 00–15 (including `docs/sprints/ROADMAP.md`) are still absent from the working tree after `4e42d8c`. `examples/seed-data.json` and `examples/booking-engine-feed.json` came back in `f553447`. Restoring the rest is outside this task. Later tasks that say "read 08" cannot, until those files return.

`docs/requirements/examples/hotel-seed-data.json` does not exist yet. Sprint 16 task 06 adds it. The tests bullet in `laravel.mdc` already points at it.

## Task 02 · Rename yachts to properties

### What was built
`yachts` is now `properties`. `yacht_id` on `cabins` and `departures` is `property_id`, with the foreign keys and the unique indexes restored as `(property_id, code)` and `(property_id, date)`. The property row carries the H20 content columns and `status` (`ACTIVE` / `INACTIVE`, default `ACTIVE`).

`Property` replaces `Yacht`. `Departure::property()` replaces `Departure::yacht()`. API resources expose `property` where they exposed `yacht`. `GET` and `PATCH /api/rms/properties` list, show and update content through `UpdatePropertyContent`, which writes `property.updated` once per real change and writes nothing on a no-op. `code` cannot be changed by that action.

`properties.manage` is on `Permission` (group Inventory). Admin already holds every permission through `isAdmin()`, and `Role::saving` clears the admin permissions column, so the grant migration is a no-op. Manager and Sales Exec are unchanged: they can view, they cannot update.

`GET /api/rms/yachts` stays as a deprecated alias of the property index until Sprint 22. The morph map alias is `property`. No stored `Yacht` class needed rewriting.

Engine settings now use `guests.max_per_property`. A data migration republishes a version when a published document still has the old key or the old charter headline and intro.

### Files touched
- `database/migrations/2026_10_02_100001_rename_yachts_to_properties.php`
- `database/migrations/2026_10_02_100002_grant_properties_manage_to_admin.php`
- `database/migrations/2026_10_02_100003_rename_max_per_yacht_in_engine_settings.php`
- `app/Models/Property.php`, `app/Enums/PropertyStatus.php`, `app/Enums/Permission.php`
- `app/Policies/PropertyPolicy.php`
- `app/Actions/Properties/UpdatePropertyContent.php`
- `app/Http/Controllers/Rms/PropertyController.php`, `app/Http/Requests/Rms/UpdatePropertyRequest.php`, `app/Http/Resources/Rms/PropertyResource.php`
- `app/Support/Roles/GrantPropertiesManage.php`
- `app/Providers/AppServiceProvider.php` (morph alias `property`)
- `routes/api/rms.php`
- Mechanical rename of `Yacht` / `yacht` to `Property` / `property` across `app/`, `tests/`, seeders, factories and views, then letterhead lines restored (see deviations)
- `docs/requirements/examples/seed-data.json`, `docs/requirements/examples/booking-engine-feed.json`
- `tests/Feature/Inventory/PropertyEndpointsTest.php`, `tests/Feature/Inventory/DeprecatedYachtAliasTest.php`
- `tests/e2e/scenarios/bookings/BKG-05-no-double-booking.md` (`whereHas('property')`)
- `tests/e2e/fixtures/reference-values.md`

### Deviations
The admin grant does not write role JSON. Writing it would be wiped by `Role::saving`, and admin does not read that column.

The migration test asserts the post-migrate fixture (2 properties, every cabin and the seeded departure point at a property). It does not call `down()`. MySQL DDL commits inside `RefreshDatabase` and would leak into later tests. The test also does not spell `yacht`, because the task's grep gate allows that word only in the deprecated-alias test.

The same grep gate forced guest-facing strings and rule ids that contained the substring `yacht` to change in PHP: "Full yacht" is "Full property", charter headline and intro defaults say property, `max per yacht` labels say `max per property`, and business-rule ids such as `ops-002-guests-per-yacht` are `ops-002-guests-per-property`. Document letterhead still says "Intimate Yacht Expeditions". All-caps `INTIMATE YACHT EXPEDITIONS` in the guest-experience brief was left as-is; the case-sensitive replace never matched it, and it is outside `app/` and `tests/`.

`fromArray()` does not fall back to `max_per_yacht`. That string cannot live under `app/` or `tests/`. Published rows are rewritten by the data migration before the new code reads them. Fresh databases publish `initial()`, which already uses the new key, so the migration no-ops there and has no Pest test that names the old key.

`code` stays fillable so seeders and the factory can set it. The update action unsets it. Facts and FAQs are validated as itinerary-style pairs, not a fixed list of six. Country is a 2-character string, not a full ISO list.

OpenAPI is Scramble, generated live at `/docs/api.json`. There is no committed `api.json`. Schema feature tests passed. `iconic-ui` types were not regenerated; the task says the frontends follow later.

### Open questions
None.

### Notes for later
Breaking change for panel, engine and portal: the JSON field `yacht` is `property` on RMS and engine resources. Those apps still call `GET /api/rms/yachts` and still read `yacht` until their types are regenerated. The alias returns the same property resource.

Task 03 renames cabins to rooms. Property index and show still embed `cabins`.

Task 08 owns the e2e label pass (HSET-01, HSET-02, INV-01, INV-12). Other scenario markdown still says yacht. Only the BKG-05 database check and the departure upsert sentence were updated, because they name the relation and the column.

Sibling `iconic-core.mdc` copies still describe yachts (task 01 note).

`composer check` was not green in one process. `tests/Unit` and `tests/Arch` passed (153). `tests/Feature` passed except three failures that this task did not introduce: `DemoAgenciesSeederTest` expects 3 agencies while `seedUnmatchedAgency()` has inserted AG-004 since `e6969d04`; `EngineSettingsSeederTest` expects `Anakata` in `details_note` while `EngineSettingsDocument::initial()` already says `Iconic` (commit `ffa842a`, that line was not in this diff); `SensitiveEncryptedCastTest` expects `DecryptException` and gets `RuntimeException` (that test and the cast were not in this diff). The combined suite then exhausts the 512MB PHP memory limit inside `ArchTest` after the feature run. Arch passes when run on its own. Pint on the changed PHP files and PHPStan (`--memory-limit=1G`) passed.

## Task 03 · Room types as data, rooms from cabins

### What was built
`cabins` is now `rooms`. `cabin_id` on `bookings` and `cabin_claims` is `room_id`, with the foreign keys restored. The generated `cabin_claims.active_key` was dropped and recreated on `room_id`. The table name `cabin_claims` stays until Sprint 22.

`room_types` holds one type per property: code (unique per property), name, occupancy (`base_occupancy`, `max_occupancy`, `max_adults`, `max_children`), `waitlist_enabled` (default true), `sort`, `status` (`ACTIVE` / `INACTIVE`), and the H20 content columns (`slug`, `description`, `size_sqm`, `bed_setup`, `amenities`, `photos`, `meta_title`, `meta_description`).

The rename migration backfills one room type per distinct `rooms.category` on each property. `SUITE` is named "Suite", `OWNER` is named "Owner's Suite". All four occupancy fields come from `engine_settings.guests.max_per_cabin`. `rooms.room_type_id` is then `NOT NULL`. `rooms.category` stays and is nullable; new writes do not set it.

`Room` replaces `Cabin`. `Room::roomType()` and `RoomType::rooms()` are the new relations. `Property::rooms()` is the same set. `Property::cabins()` and `Booking::cabin()` still exist and point at `Room` on `room_id`, so existing eager loads keep working.

Decisions that used to read a cabin category now read `roomType->code`. Availability and the calendar still put that code in the snapshot field `category`, so SUITE and OWNER behaviour is unchanged. Pricing still takes `CabinCategory`. `Room::pricingCategory()` maps `OWNER` to `CabinCategory::Owner` and every other code to `CabinCategory::Suite`, and each pricing use is marked `// TODO(Sprint 18): room type pricing (09 H8)`.

`GET` and `POST /api/rms/properties/{property}/room-types` and `/rooms`, plus `PATCH` and `POST …/deactivate` on `room-types/{roomType}` and `rooms/{room}`. Create and update require `properties.manage`. List and show require `panel.rms`. History events are `room_type.created`, `room_type.updated`, `room_type.deactivated`, `room.created`, `room.updated`, `room.deactivated`. A no-op update writes nothing. Deactivate of an already inactive row writes nothing. Deactivate returns 409 while any of that room's (or that type's rooms') claims are still active on a departure dated today or later.

Occupancy validation rejects `base_occupancy`, `max_adults` or `max_children` above `max_occupancy`, and `max_adults` below 1. Those checks still run when slug or meta fields fail in the same request.

### Files touched
- `database/migrations/2026_10_02_100004_create_room_types_table.php`
- `database/migrations/2026_10_02_100005_rename_cabins_to_rooms.php`
- `app/Models/Room.php`, `app/Models/RoomType.php`, `app/Models/Property.php`, `app/Models/Booking.php`, `app/Models/CabinClaim.php`
- `app/Enums/RoomStatus.php`, `app/Enums/RoomTypeStatus.php`
- `app/Support/Rooms/BackfillRoomTypes.php`, `RoomTypeOccupancy.php`, `FutureClaimGuard.php`
- `app/Actions/RoomTypes/CreateRoomType.php`, `UpdateRoomType.php`, `DeactivateRoomType.php`
- `app/Actions/Rooms/CreateRoom.php`, `UpdateRoom.php`, `DeactivateRoom.php`
- `app/Http/Controllers/Rms/RoomTypeController.php`, `RoomController.php`
- `app/Http/Requests/Rms/StoreRoomTypeRequest.php`, `UpdateRoomTypeRequest.php`, `StoreRoomRequest.php`, `UpdateRoomRequest.php`
- `app/Http/Resources/Rms/RoomTypeResource.php`, `RoomResource.php`, `PropertyResource.php`
- `app/Policies/RoomTypePolicy.php`, `RoomPolicy.php`
- `app/Providers/AppServiceProvider.php` (morph aliases `room`, `room_type`)
- `app/Services/Inventory/Availability.php`, `app/Http/Controllers/Rms/CalendarController.php`
- `app/Support/Metrics/CommercialMetrics.php` (berth count reads `rooms`)
- `database/seeders/InventorySeeder.php`, `database/factories/RoomFactory.php`, `RoomTypeFactory.php`
- `routes/api/rms.php`
- `tests/Feature/Inventory/RoomEndpointsTest.php`, `InventorySeederTest.php`, `PropertyEndpointsTest.php`
- `tests/Unit/Support/Rooms/RoomTypeOccupancyTest.php`
- `app/Models/Cabin.php`, `database/factories/CabinFactory.php`, `app/Http/Resources/Rms/CabinResource.php` removed
- Mechanical rename of the `Cabin` class and `cabin_id` column across `app/`, `tests/`, seeders and factories. `BookingType::Cabin`, `QuoteType::Cabin`, guest-facing "Cabin" copy, and the engine `cabins` payload key were put back.

### Deviations
`cabins()` and `cabin()` stay as relation names. Hundreds of eager loads and JSON keys named `cabin` would otherwise change, and this sprint must not change behaviour except the labels this task names. The property resource is the label change: the embed key is `rooms`, each with `floor`, `status` and `room_type`.

The occupancy number is `CurrentConfig` `guests.maxPerCabin` when engine settings are published. When they are not (seeders and a fresh migrate before publish), it is `EngineSettingsDocument::initial()['guests']['max_per_cabin']`. That is the document default, not a new literal. A fresh test database has no cabin rows at migrate time, so the migration backfill no-ops; `InventorySeeder` calls the same backfill after insert.

`EnginePartyRules` and `GuestCapacity` still read `max_per_cabin`. H6 moves guest-count checks onto the room type. This task's build list does not rewrite those classes. The backfill copies the same number onto every seeded type, so a yacht party still hits the same limit.

`pricingCategory()` prices any code other than `OWNER` as `CabinCategory::Suite`. A hotel type created in the API prices as a suite until Sprint 18.

Waitlist `cabin_category`, offer `cabin_types`, portal request `category`, `BookingType::Cabin`, `QuoteType::Cabin`, `CabinClaim`, and the engine `/cabins` payload stay. They are not reads of `rooms.category`. The Sprint 18 TODO is on the pricing path only. Marking waitlist with a pricing TODO would name the wrong sprint (waitlist schema is H21).

View versus mutate follows `PropertyPolicy`: sales can list rooms, sales cannot create them. The task names `properties.manage` for the write path.

No test calls migration `down()`. MySQL DDL commits inside `RefreshDatabase`. The inventory seeder test is the backfill proof: two types per property, every room typed, eight `SUITE` rooms and one `OWNER` room, occupancy equal to the document default.

### Open questions
None.

### Notes for later
Sprint 17 owns claims by night. Sprint 18 owns room-type rates and should delete `pricingCategory()` and `CabinCategory`. Sprint 22 drops `rooms.category`, `CabinCategory`, and the `cabin_claims` table name.

Engine party rules should read the room type's `max_adults` / `max_children` / `max_occupancy` when that move is in scope. Until then a new type's occupancy is stored and validated, and the engine still enforces `max_per_cabin`.

Property JSON `cabins` is now `rooms`. Panel types are not regenerated. OpenAPI is live at `/docs/api.json`.

Task 08 owns the e2e label pass. Scenario markdown that still says cabin was left alone.

The three feature failures recorded under task 02 are unchanged: `DemoAgenciesSeederTest`, `EngineSettingsSeederTest`, `SensitiveEncryptedCastTest`. Pint and PHPStan passed. `tests/Feature` and `tests/Concurrency` passed except those three. `tests/Unit` passed, including the occupancy matrix. The combined suite with `tests/Arch` was not run in one process (task 02: 512MB exhausts inside `ArchTest` after features).

## Task 04 · StayDates and the stay clock

### What was built
`StayDates` is the half-open stay `[check_in, check_out)`. `of()` rejects `check_out <= check_in` and a string that is not a real `Y-m-d`. `forNights()` builds the same interval. `nights()`, `eachNight()` (never yields `check_out`), `lastNight()`, `contains()`, `overlaps()` (a stay that starts on the other's check-out does not overlap), `equals()` and `toArray()` are on that type. Internally the dates are UTC midnights, so the night count does not depend on a time zone.

`StayClock` reads "today" from `BusinessTime` (property-local date, H18). `daysUntilArrival` is 0 on the arrival day. `daysSinceCheckOut` is 0 on the check-out day. `arrivalMoment` and `checkOutMoment` place `stay.check_in_time` and `stay.check_out_time` on those dates in the business time zone and return UTC. `isArrivalDayOrLater` is `daysUntilArrival <= 0`.

Until task 05 publishes the stay section, one private method (`// TODO(16-05)`, `// TODO(OPEN: HQ3)`) returns the demo values from that task: check-in `15:00`, check-out `11:00`, max nights `30`.

`ValidatesStay` supplies `check_in` / `check_out` rules (`date_format:Y-m-d`, `after:check_in`) and, after those rules pass, builds a `StayDates` and rejects a stay longer than `maxNights()`. `StayRequest` applies the trait. No route uses it yet.

`Departure::stayDates()` is `StayDates::forNights` on the departure date and the itinerary nights (or 7 when nights is 0). `returnDate()` is that stay's check-out. The only `addDays($nights)` on a stay is inside `StayDates`.

### Files touched
- `app/Support/Stays/StayDates.php`
- `app/Support/Stays/StayClock.php`
- `app/Http/Requests/Concerns/ValidatesStay.php`
- `app/Http/Requests/StayRequest.php`
- `app/Models/Departure.php`
- `tests/Unit/Support/Stays/StayDatesTest.php`
- `tests/Feature/Stays/StayClockTest.php`

### Deviations
`StayClock` tests live under `tests/Feature` because `BusinessTime` reads `config()` and the departure assertion needs the database. `tests/Unit` does not boot Laravel. `StayDates` itself is a unit test with no database.

Galápagos (`Pacific/Galapagos`) does not observe DST. The DST test sets `iconic.business_timezone` to `America/New_York` for that case: 15:00 on 2026-03-07 is 20:00 UTC, and 15:00 on 2026-03-09 is 19:00 UTC. The test restores `Pacific/Galapagos`.

`StayRequest` exists so PHPStan analyses the trait (an unused trait is skipped) and so the validation hook has a FormRequest. Caller migration is out of scope, so nothing routes it.

The image has no pcov or xdebug, so Pest could not print a coverage percentage. Each line of `StayDates`, `StayClock`, `ValidatesStay` and `StayRequest` is executed by the tests above.

### Open questions
None.

### Notes for later
Task 05 replaces `StayClock::stayTimes()` with `CurrentConfig` and should assert `arrivalMoment` uses the published time. Availability still does not enforce `min_nights` / `max_nights` (Sprint 17). Other "days after return" callers still start from `returnDate()`; they were not moved onto `StayClock`.

## Task 05 · Stay business rules

### What was built
`StayRules` is a section on `BusinessRulesDocument` under `stay`. The eight demo values live only in `initial()`, marked `// TODO(OPEN: HQ3) demo value`: check-in `15:00`, check-out `11:00`, no-show cutoff `23:59`, min nights `1`, max nights `30`, max rooms per booking `5`, check-in requires full payment `true`, booking horizon `730` days.

`rules()` requires `H:i` times, `min_nights` at least 1, `max_nights` from 1 to 365 and at least `min_nights`, `max_rooms_per_booking` from 1 to 50, a boolean payment flag, and `booking_horizon_days` from 30 to 1095. `labels()` names each field for the change list. `warnings()` adds a soft warning on `stay.check_out_time` when check-out is later than check-in, because a room cannot be turned over the same day. The demo times do not warn.

The migration `2026_10_03_100001_add_stay_to_business_rules` publishes one new business-rules version as System when the current document is missing any stay key. Approval reference: `Sprint 16: stay added (defaults demo, source 09 H3)`. A fresh seed already publishes `initial()`, so the migration does nothing and `iconic:config-verify` stays on version 1. A document from before the section fails verify, then the migration publishes version 2 and verify passes.

The registry has a Stay group with eight labelled rows, all `PENDING CLIENT` and source `HQ3`. `ops-001-duration` is still locked and still displays `7 nights · Sunday → Sunday`. A note marks it retired by 09 H2. Sprint 22 deletes the row.

`StayClock` reads `stay.check_in_time`, `stay.check_out_time` and `stay.max_nights` from `CurrentConfig`. The `TODO(16-05)` hardcoded times are gone. `arrivalMoment` for a published check-in of `16:30` in `Pacific/Galapagos` is `22:30` UTC.

Fresh-seed registry counts are 99 rows, 74 here, 15 on other pages, 10 locked, 51 flagged. `BR-01` and the registry facts in `reference-values.md` match those counts.

### Files touched
- `app/Support/Config/Documents/StayRules.php`
- `app/Support/Config/Documents/BusinessRulesDocument.php`
- `app/Support/Config/Documents/BusinessRulesConstraint.php`
- `app/Support/BusinessRules/Registry.php`
- `app/Enums/RuleGroup.php`
- `app/Support/Stays/StayClock.php`
- `database/migrations/2026_10_03_100001_add_stay_to_business_rules.php`
- `tests/Feature/Config/StayRulesTest.php`
- `tests/Feature/Config/AddStayRulesToBusinessRulesMigrationTest.php`
- `tests/Feature/Config/BusinessRulesSeederTest.php`
- `tests/Feature/Config/BusinessRulesEndpointsTest.php`
- `tests/Feature/Config/BusinessRulesRegistryTest.php`
- `tests/Feature/Stays/StayClockTest.php`
- `tests/e2e/scenarios/config/BR-01-fresh-seed-registry.md`
- `tests/e2e/fixtures/reference-values.md`
- `iconic-ui/app/types/config.ts` (`RuleGroup` mirror)

### Deviations
`RuleStatus` has no retired value, and the panel status pill switches on the five existing statuses. `ops-001-duration` stays `CONFIRMED` and `locked`. The retired mark is the note, which also increments `differs_or_flagged`. The quoted display `7 nights · Sunday → Sunday` is unchanged.

A fresh database does not get a second version. `ConfigSeeder` publishes `initial()`, which already contains `stay`, and the migration returns. The pre-change test is the one that publishes exactly one new version.

`StayClock` tests seed `ConfigSeeder` because the clock now throws when no business rules are published.

`iconic-ui` `RuleGroup` also gained `crm`, which the PHP enum already had and the hand mirror had omitted.

### Open questions
None.

### Notes for later
Sprint 17 uses `min_nights` and `max_nights` in availability. Sprint 19 uses `check_in_requires_full_payment` at check-in. Sprint 22 deletes `ops-001-duration`. `max_rooms_per_booking` and `booking_horizon_days` are stored and listed; nothing enforces them yet.

## Task 06 · Hotel fixture and seeders

### What was built
`docs/requirements/examples/hotel-seed-data.json` is the hotel fixture. `meta` marks every value as demo. It holds one property, Hotel Demo (`HTL`), four room types (`STD`, `TWN`, `FAM`, `STE`) and 24 rooms (`101`–`108`, `201`–`208`, `301`–`308`: 10 standard, 6 twin, 5 family, 3 suite).

Rates follow the 09 H8 shape: seasons, nightly room rates, occupancy, day-of-week percents, length-of-stay bands, one festive supplement, and two rate plans with `BAR` as the only default. Restrictions cover a stop-sell week (10–16 Aug 2026), a min-stay-3 weekend (3–5 Jul 2026) and a closed-to-arrival day (1 Sep 2026, standard rooms). Thirty bookings cover lengths 1–14, an arrival on every weekday, group `GRP-001` on three rooms with different dates, and two same-room pairs that touch on check-out / check-in. Those bookings are not inserted.

Eight reference quotes carry hand arithmetic in `working`. Per night the order is room nightly, occupancy extras, single occupancy, day-of-week, supplement, then rate-plan adjust. Length-of-stay applies once to the stay subtotal, half-up. The numbers before `=` sum to `expected_total`, and so do `expected_lines`.

`HotelSeeder` runs only in `local` and `testing`. It upserts the property, room types and rooms. A `// TODO(Sprint 19)` marks where bookings and claims will go.

`ICONIC_SEED_MODE` is `yacht` or `hotel`, default `yacht`, read as `config('iconic.seed_mode')`. Hotel mode skips yacht inventory and the yacht demo seeders, then calls `HotelSeeder`. `php artisan migrate:fresh --seed` with the flag set to `hotel` produced 1 property (`HTL`), 4 room types and 24 rooms. The local database was then seeded again in the default yacht mode.

`hotelFixture(string $path)` reads the JSON for tests.

### Files touched
- `docs/requirements/examples/hotel-seed-data.json`
- `database/seeders/HotelSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `config/iconic.php`
- `tests/Pest.php`
- `tests/Feature/Hotels/HotelFixtureTest.php`
- `README.md`
- `.env.example`

### Deviations
There was no existing fixture helper to mirror. `hotelFixture()` loads the hotel JSON and returns `data_get` for the path.

Larastan rejects `env()` outside config files. The seeder reads `config('iconic.seed_mode')`, and that config key reads `ICONIC_SEED_MODE`.

`country` is null. The column is two characters, and the fixture does not invent an ISO code.

### Open questions
None.

### Notes for later
Sprint 18 prices from `room_rates` and the eight quotes. Sprint 19 seeds the bookings and claims, and switches the default seed mode to `hotel`.

## Task 07 · Shared UI: stay types and date-range input

### What was built
`useDates` now has `nightsBetween`, `eachNight`, `addNights` and `formatStay`. They use the existing UTC calendar-date parse, so a stay does not shift with the time zone. `formatStay` puts the year on the check-out when both dates share a year, and on both dates when they do not. One night reads `1 night`.

`AnkStayInput` is a two-month Nuxt UI range calendar. `minNights` and `maxNights` are required props. `minDate`, `maxDate` and `nightInfo` are optional. The first click is check-in. The second click is check-out. A closed-to-arrival day, a closed-to-departure day, a disabled night, or a length outside the minimum or maximum is refused with an inline status message, and the stay is not committed. The arrival night's `minStay` raises the minimum. `AnkNights` is the nights pill. Labels live in `i18n/locales/en.json`.

The playground Dates section shows both components. Demo bounds there are 1 and 14 nights, labelled as style-guide bounds. Apps pass the published stay rules.

OpenAPI types were regenerated from `http://127.0.0.1:18000/docs/api.json` (the API container is on 18000 because 8000 is taken).

### Files touched
- `iconic-ui/app/composables/useDates.ts`
- `iconic-ui/app/components/AnkStayInput.vue`
- `iconic-ui/app/components/AnkNights.vue`
- `iconic-ui/i18n/locales/en.json`
- `iconic-ui/.playground/app/components/SgDates.vue`
- `iconic-ui/tests/unit/useDates.test.ts`
- `iconic-ui/tests/components/AnkStayInput.test.ts`
- `iconic-ui/app/types/api.d.ts`
- `iconic-ui/app/types/inventory.ts`
- `iconic-ui/CHANGELOG.md`

### Deviations
The task's sample `Tue 3 Mar – Fri 6 Mar 2028` has the wrong weekdays. 3 Mar 2028 is a Friday and 6 Mar 2028 is a Monday. `formatStay('2028-03-07', '2028-03-10')` is `Tue 7 Mar – Fri 10 Mar 2028 · 3 nights`.

`minNights` and `maxNights` have no default. A default would be a second business value.

Closed days stay clickable. Marking them unavailable would swallow the click before the inline reason can show. A refused check-in clears the calendar selection so the next click does not complete a stay from that day.

The regenerated spec no longer has `YachtResource`. The `Yacht` alias in `inventory.ts` now points at `PropertyResource`.

Nothing was committed. Suggested commands:

```bash
cd iconic-ui
git add \
  app/composables/useDates.ts \
  app/components/AnkStayInput.vue \
  app/components/AnkNights.vue \
  i18n/locales/en.json \
  .playground/app/components/SgDates.vue \
  tests/unit/useDates.test.ts \
  tests/components/AnkStayInput.test.ts \
  app/types/api.d.ts \
  app/types/inventory.ts \
  CHANGELOG.md
git commit -m "$(cat <<'EOF'
Add shared stay date helpers and the two-month stay picker.

EOF
)"

cd ../iconic-api
git add docs/sprints/sprint-16/REPORT.md
git commit -m "$(cat <<'EOF'
Record sprint 16 task 07 stay primitives.

EOF
)"
```

`app/types/config.ts` is still dirty from task 05. Leave it out of the task 07 commit.

### Open questions
None.

### Notes for later
Panel screens still import `Yacht` and call `/api/rms/yachts`. The alias is now `PropertyResource` (`rooms`, not `cabins`), so those screens need a pass before the panel typechecks.

`nightInfo.price` is on the type and is not drawn in the day cell.

## 08 — Sprint close: e2e and report

### What was built
Hotel scenarios `HSET-01` and `HSET-02`. `INV-01` and `INV-12` now say property headers and room rows. The sidebar title stays **Yacht Layout**. Those two files were not updated in tasks 02 or 03; both reports left the label pass for this task. `INDEX.md` lists HSET-01 and HSET-02 as batch B21. The sprint README E2E line already named them, so it was left as written.

The P1 set was not run. `tests/e2e/bin/up.sh` was not started. Its first step copies `tests/e2e/environment/api.env` over `iconic-api/.env`. Host port 8000 and 8001 belong to `keevaris-api`. The working API containers already use the names `iconic-api`, `iconic-mysql`, `iconic-redis`, and `iconic-mailpit`, and mailpit already owns 8025. Memory available was 10 GiB, above the 6 GiB warning. Detail: `tests/e2e/runs/2026-10-03-1615-sprint-16-p1.md`. Ledger: `tests/e2e/runs/LEDGER.md`.

An ad-hoc panel on port 3001, pointed at the API on 18000, reached the login page. Sign-in returned `CSRF token mismatch.` The process was stopped. That attempt is not a P1 pass.

### Sprint summary
Rules no longer say departures are Sundays. Yachts and cabins are stored as properties, room types, and rooms. `StayDates` and `StayClock` exist. The business-rules document has a Stay group of eight HQ3 demo values. A hotel fixture exists behind `ICONIC_SEED_MODE=hotel`; the default seed is still yacht. The shared layer has `AnkStayInput`, `AnkNights`, and the stay date helpers. The panel still says Yacht and Cabin, and the calendar still reads `departure.yacht` while the departure resource returns `property`.

### Accepted reasons (P1 did not pass)
- **ENV.** The e2e stack cannot start beside `keevaris-api` and the working iconic containers without replacing `.env` and colliding on container names and ports 8000, 8001, and 8025.
- **HSET-01.** The RMS has no Property or Room types screen. Sprint 20 task 01 adds Booking engine → Property and Room types. The scenario is the contract for that screen. A missing menu item is not a pass.
- **HSET-02.** The Stay group is in the registry. The panel edits a single rule path with `ConfigNumberInput`, and check-in time is `15:00`. That control cannot publish `16:00`. Not confirmed in the browser (login stopped first).

### Files touched
- `tests/e2e/scenarios/hotel/HSET-01-properties-and-room-types.md` (new)
- `tests/e2e/scenarios/hotel/HSET-02-stay-rules.md` (new)
- `tests/e2e/scenarios/inventory/INV-01-seeded-calendar.md`
- `tests/e2e/scenarios/inventory/INV-12-lucia-read-only.md`
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/2026-10-03-1615-sprint-16-p1.md` (new)
- `tests/e2e/runs/LEDGER.md` (new)
- `docs/sprints/sprint-16/REPORT.md`

### Deviations
`up.sh` was not executed. Running it would replace the local `.env` and fight containers that are already up. The task's done-when allows a written reason. No product code was changed during the attempted login.

`composer check` was not re-run. This task did not change PHP. The three failures recorded under task 02 are unchanged. `pnpm lint`, `typecheck`, and `test` in iconic-ui were not re-run. This task did not change that repo.

### Open questions
Stay values remain HQ3 demo (`15:00`, `11:00`, `23:59`, 1 night, 30 nights, 5 rooms, full payment at check-in, 730 days). No new HQ id.

### Notes for later
- Panel calendar `groupColumnsByDate` still keys `departure.yacht`. `DepartureResource` returns `property`. INV-01 will fail on a blank grid until that read is updated.
- `RulesCurrentCell` needs a time control before HSET-02 step 3 can pass.
- HSET-01 waits on sprint 20 task 01.
- Bring the e2e stack up only when ports 8000, 8001, 8025, 3000, and 3001 are free and replacing `iconic-api/.env` is acceptable. Do not stop `keevaris-api` to free 8000.

### Git commands for the user
Nothing was committed. Task 07 files are still uncommitted. Leave `iconic-ui/app/types/config.ts` out (task 05).

```bash
cd iconic-ui
git add \
  app/composables/useDates.ts \
  app/components/AnkStayInput.vue \
  app/components/AnkNights.vue \
  i18n/locales/en.json \
  .playground/app/components/SgDates.vue \
  tests/unit/useDates.test.ts \
  tests/components/AnkStayInput.test.ts \
  app/types/api.d.ts \
  app/types/inventory.ts \
  CHANGELOG.md
git commit -m "$(cat <<'EOF'
Add shared stay date helpers and the two-month stay picker.

EOF
)"

cd ../iconic-api
git add \
  docs/sprints/sprint-16/REPORT.md \
  tests/e2e/scenarios/hotel/HSET-01-properties-and-room-types.md \
  tests/e2e/scenarios/hotel/HSET-02-stay-rules.md \
  tests/e2e/scenarios/inventory/INV-01-seeded-calendar.md \
  tests/e2e/scenarios/inventory/INV-12-lucia-read-only.md \
  tests/e2e/scenarios/INDEX.md \
  tests/e2e/runs/2026-10-03-1615-sprint-16-p1.md \
  tests/e2e/runs/LEDGER.md
git commit -m "$(cat <<'EOF'
Close sprint 16 with hotel e2e scenarios and the P1 ledger.

EOF
)"
```

Task 01–06 files are separate commits if they are still unstaged. This command covers task 07 and task 08 only.
