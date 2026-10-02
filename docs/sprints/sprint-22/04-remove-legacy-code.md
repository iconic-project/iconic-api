# 22-04 — Remove legacy code, enums and config keys; vocabulary Arch test

**Repo:** iconic-api
**Depends on:** 22-03
**Read first:** 09 §2 naming rule; `tests/Arch/ArchTest.php`; every `TODO(Sprint …)` left by sprints 16–21 (`grep -rn "TODO(Sprint" app tests`)

## Build
1. Delete: `CabinCategory`, `DepartureStatus`, `PngCategory` (enum and `Support/Guests/PngCategory.php`), `Support/Guests/ApplyPng.php`, `Support/Guests/AndeanCommunity.php`, `SeasonPattern` (yacht seasons), `ManifestKind/Format/Reason` (if no history presentation needs them — else move to `Enums/Archive`), `CabinPricer`, `ReservationQuoter` (if fully replaced by `StayQuoter`), `DepartureLocks`, `DepartureSnapshot`, `Support/Departures/*`, `Support/Itineraries/*`, `Actions/Departures/*`, `Actions/Itineraries/*`, `Actions/Manifests/*`, `VoyageStatus*`, deprecated route aliases (`/rms/yachts`, `/rms/departures*`, `/rms/itineraries*`, `/engine/feed`, `/engine/departures/*`), deprecated template variable `{{departure_date}}`, `ICONIC_SEED_MODE=yacht` and the yacht seeder/fixture loading (keep `seed-data.json` in docs as historical reference, unused).
2. Config migrations removing legacy keys: rates `years`, `rules.*` yacht keys, `terms.charter_*`/`cabin_*` (if superseded by plans), engine settings `fees.png`, `fees.tct_pp`, `guests.max_per_cabin`, `guests.max_per_yacht`, `charter` (if 22-02 Path B), business rules `manifests`, `charter` (Path B), registry entry `ops-001-duration`. One publish per document, `approval_reference` `Sprint 22: legacy keys removed (09 H19)`.
3. **Vocabulary Arch test** (`tests/Arch/VocabularyTest.php`): no class, method, property, route or string literal in `app/` and `routes/` matches `/departure(?!s? time)|yacht|cabin|itinerar|voyage|cruise|\bpng\b|ppdo|embark/i`, except: `App\Models\Archive\*`, `App\Enums\Archive\*`, and the word "departures" in front-desk contexts (`FrontDesk*`, i18n keys `front_desk.departures*`). Allowlist lives in the test with a reason per entry.
4. Rename remaining identifiers flagged by the test (e.g. `CabinUnavailableException` → already `RoomUnavailableException`; `OfferWaitlistCabins` → `OfferWaitlistRooms`).
5. `PermissionCollection` already ignores unknown permission strings — remove yacht-only permissions from the enum (e.g. departures/itineraries/manifests) and add a migration cleaning them from `roles.permissions`, logging what was removed.

## Tests
Arch tests green; `composer check` green; `iconic:config-verify` green.

## Done when
`grep -rniE "yacht|cabin|itinerar|voyage|cruise" app/ routes/` returns only allowlisted archive paths.
