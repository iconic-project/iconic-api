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
