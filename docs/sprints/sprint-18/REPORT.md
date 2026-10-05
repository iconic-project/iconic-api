# Sprint 18 · Report

Each task appends its section below.

## Task 01 · Rates document v2

### What was built

The published rates document is schema version 2. It still carries `years`, `terms`, and `rules` so `CabinPricer` can read yacht prices until Sprint 22. The new keys are `seasons`, `room_rates`, `occupancy`, `day_of_week`, `length_of_stay`, `supplements`, and `rate_plans`.

`rules()` refuses overlapping seasons and names both codes. Touching ranges (`to = D`, `from = D+1`) pass. A range that shares a night with the next season fails. Season codes are distinct, `from` is on or before `to`, and gaps are allowed. Each `(room_type, season)` pair appears at most once. The room type must exist and be `ACTIVE`. The season code must be one of `seasons`. Nightly rates are integers of at least 1. Occupancy, weekday adjustments, length-of-stay bands, and supplements follow the bounds in the task. A non-empty rate-plan list has exactly one default. An empty list is valid, so a production document with no plans yet still passes `iconic:config-verify`.

`warnings()` stays soft: an active room type missing a rate in some season, nights inside the engine calendar horizon that sit in no season, and a supplement that includes a night outside every season. The existing yacht year-on-year warnings stay.

`labels()` covers every fixed path. Yacht `years` and the eight legacy rule fields are labelled "Legacy (yacht)". `publishErrors()` refuses a new publish that changes those values. `terms` stay editable. The validate endpoint still reports yacht warnings; the freeze applies on publish.

`fromArray()` stays lenient. A missing weekday is 0. A missing `schema_version` is 0, so a pre-v2 row fails `iconic:config-verify` until the migration publishes version 2.

The migration `2026_10_05_130001_add_rates_document_v2` republishes as System with approval reference `Sprint 18: rates v2 added (09 H8)`. It copies `years`, `terms`, and `rules` unchanged. Local and testing take the hotel fixture. Other environments get empty lists, so every night is unrated until real rates are published. A second run does nothing. `down()` is a no-op because versions are append-only.

### Files touched

- `app/Support/Config/Documents/RatesDocument.php`
- `app/Support/Config/Documents/Rates/Season.php`
- `app/Support/Config/Documents/Rates/RoomRate.php`
- `app/Support/Config/Documents/Rates/Occupancy.php`
- `app/Support/Config/Documents/Rates/DayOfWeek.php`
- `app/Support/Config/Documents/Rates/LengthOfStayBand.php`
- `app/Support/Config/Documents/Rates/Supplement.php`
- `app/Support/Config/Documents/Rates/RatePlan.php`
- `app/Support/Config/Documents/Rates/RatesV2.php`
- `app/Support/Config/Documents/Rates/SeasonsDoNotOverlap.php`
- `app/Support/Config/Documents/Rates/KnownSeason.php`
- `app/Support/Config/Documents/Rates/UniqueRoomRates.php`
- `app/Support/Config/Documents/Rates/ExactlyOneDefaultRatePlan.php`
- `app/Support/Config/Documents/Rates/DayOfWeekKeys.php`
- `database/migrations/2026_10_05_130001_add_rates_document_v2.php`
- `app/Http/Resources/Rms/RatesCurrentResource.php`
- `app/Http/Resources/Rms/ConfigVersionDetailResource.php`
- `tests/Feature/Config/RatesDocumentV2Test.php`
- `tests/Feature/Config/AddRatesV2MigrationTest.php`
- `tests/Feature/Config/RatesDocumentTest.php`
- `tests/Feature/Config/RatesSeederTest.php`
- `tests/Feature/Config/RatesEndpointsTest.php`
- `tests/Feature/Config/BusinessRulesRegistryTest.php`
- `tests/Feature/Config/DepartureConfigChecksTest.php`
- `tests/Feature/Bookings/MoveBookingTest.php`
- `tests/Feature/Engine/EngineCheckoutStripeTest.php`
- `docs/sprints/sprint-18/REPORT.md`

### Deviations

Lists that may be empty (`seasons`, `room_rates`, `length_of_stay`, `supplements`, `rate_plans`, `day_of_week`) use `present|array`. Laravel's `required` rule treats `[]` as missing, which would reject the production empty document and a fresh seed that has no room rates yet. A missing key still fails `iconic:config-verify`.

Fixture `room_rates` are kept only for room types that already exist and are `ACTIVE`. `ConfigSeeder` runs before `HotelSeeder`, and a yacht seed creates no room types, so `exists` against `room_types` would reject the full fixture. A fresh seed therefore stores seasons and plans from the hotel fixture and an empty `room_rates` list. The migration writes all 16 fixture rates when STD, TWN, FAM, and STE are already active.

An empty `rate_plans` list does not require a default. The "exactly one default" rule applies when the list has rows. That is what lets an empty production document pass config-verify.

Legacy yacht prices are frozen in `publishErrors()`, not in `rules()`. A draft can still be validated with a different suite price; publishing it is refused. Tests that previously published a suite-price change now publish `occupancy.extra_adult_nightly` or `terms.cabin_balance_days` so a new version can still be written. `FallBackOnlineDeposit` reprices from the booking's frozen rates version, so the Stripe fallback does not depend on a new suite price.

Cancellation band-set codes are a non-empty string. Whether the code exists is task 03.

### Open questions

Meal plan codes `RO`, `BB`, `HB`, and `FB` are industry abbreviations. 09 does not list their labels. `TODO(OPEN: 09 H8)` is on the meal-plan rule.

### Notes for later

A fresh local seed has empty `room_rates` until the hotel room types exist and a later publish includes them. Do not reorder `DatabaseSeeder` in this task. Task 02 (`RoomPricer`) should republish, or seed hotel types before the rates document, if the fixture quotes need those 16 rates on a clean database.

`RoomPricer`, taxes, rate-plan behaviour, and the panel editor are tasks 02–06. E2E scenarios are task 07.

## Task 02 · RoomPricer

### What was built

`RoomPricer::quote()` prices one stay from a `RatesDocument`, a `RoomType`, and a `StayQuoteInput`. It does not read the database or published config. Each night, in order: season, nightly room rate, occupancy extras (adults fill `base_occupancy` first), single occupancy, day of week, supplements, then the rate plan's `adjust_pct`. Each percentage step uses `Rounding::halfUp`. The length-of-stay discount is the highest band whose `min_nights` is at most the stay, applied once to the sum of the night totals.

`StayQuote` carries `night_lines`, summary `lines`, `total`, `deposit_pct`, `deposit`, `rates_version_id`, and stay `terms`. Summary labels are keys in `lang/en/pricing.php`, rendered by `QuoteLabels`. The pricer does not build English sentences. Deposit and balance timing come from the rate plan (task 03). `promo` is stored and unused until Sprint 22.

A night with no season, or a season with no rate for the room type, returns `NoRate` and names that night: `No rate for {type} on {date}`. An unknown plan returns `NoRate` rather than a zero adjustment. Guest-count limits are not checked.

The eight `reference_quotes` match code and amount. Per-night rounding is the one that wins: three nights at 5 with a +10% plan are 18, while rounding `15 × 1.10` once would be 17.

### Files touched

- `app/Services/Pricing/RoomPricer.php`
- `app/Services/Pricing/StayQuoteInput.php`
- `app/Services/Pricing/StayQuote.php`
- `app/Services/Pricing/StayQuoteLine.php`
- `app/Services/Pricing/NightLine.php`
- `app/Services/Pricing/QuoteLabels.php`
- `app/Support/Config/Documents/Rates/DayOfWeek.php`
- `lang/en/pricing.php`
- `tests/Unit/Services/Pricing/RoomPricerTest.php`
- `docs/sprints/sprint-18/REPORT.md`

### Deviations

`rates_version_id` is an optional field on `StayQuoteInput` and is copied onto the quote. The task's constructor list did not include it, and the quote method does not look it up.

`RoomPricer` is not called from `ReservationQuoter`. Price check and booking quotes are task 05.

### Open questions

`guests.infant_max_age` is not defined. `TODO(OPEN: 09 H8)` is on `StayQuoteInput`. Ages in `childAges` are priced as children. Callers must refuse younger guests until the rule exists.

### Notes for later

Task 05 should call `RoomPricer` and refuse stays whose child ages are not yet classified. Pass the plan's cancellation set into `QuoteTerms::forPlan` when a penalty is needed. `RoomPricer` does not load band contents.

## Task 03 · Rate plans

### What was built

Business rules `cancellation` keeps `bands` and `charter_bands` and adds `sets`. `STANDARD` is the cabin bands. `CHARTER` is the charter bands. A config migration publishes that with approval reference `Sprint 18: cancellation sets (09 H8)`. Publishing rates refuses a plan whose `cancellation` code is not a set, with `Cancellation set {code} does not exist.`

`QuoteTerms::forPlan` carries `deposit_pct`, `balance_days`, `refundable` and `cancellation_set` from the plan. A non-refundable plan ignores the set: the penalty is 100% at every band and the summary is `Non-refundable`. Yacht quotes still return only `balance_days` and `charter`.

`RoomPricer` fills `deposit_pct` and the deposit amount from the plan and attaches those terms to `StayQuote`. `RatePlanOptions::forStay` returns every published plan.

The same one-night stay under BAR and NR differs in the plan line, the deposit and the terms. The room line is the same.

### Files touched

- `app/Support/Config/Documents/BusinessRulesDocument.php`
- `app/Support/Config/Documents/RatesDocument.php`
- `app/Support/BusinessRules/Registry.php`
- `app/Services/Pricing/QuoteTerms.php`
- `app/Services/Pricing/RoomPricer.php`
- `app/Services/Pricing/StayQuote.php`
- `app/Support/Rates/RatePlanOptions.php`
- `app/Http/Resources/Rms/BusinessRulesCurrentResource.php`
- `app/Http/Resources/Rms/ConfigVersionDetailResource.php`
- `database/migrations/2026_10_05_125001_add_cancellation_sets.php`
- `tests/Feature/Config/AddCancellationSetsMigrationTest.php`
- `tests/Feature/Config/AddRatesV2MigrationTest.php`
- `tests/Unit/Services/Pricing/QuoteTermsTest.php`
- `tests/Unit/Services/Pricing/RoomPricerTest.php`
- `docs/sprints/sprint-18/REPORT.md`

### Deviations

`bands` and `charter_bands` stay. Readers of those keys are unchanged. `sets` is added beside them.

The hotel fixture plans use `standard` and `non_refundable`. Those two sets are copies of the cabin bands, so a seeded rates publish resolves. A non-refundable plan does not use the set's percentages.

The set-exists check is `publishErrors`, not `rules()`. Rates are seeded before business rules, and the seeder does not run `publishErrors`.

`RoomPricer` stays free of `CurrentConfig`. It records the set code and does not load the bands. `QuoteTerms::penaltyPct` uses bands passed to `forPlan`.

`Booking::balanceDueDate()` still counts `balance_days` back from the departure date. Stay balance timing (days before `check_in`) is for the quote terms. Existing booking `deposit_pct` and `balance_days` are not rewritten by the migration.

### Open questions

Meal-plan labels remain `TODO(OPEN: 09 H8)`. The infant age rule is unchanged from task 02.

### Notes for later

Task 05 should price stays through `RoomPricer` and pass the plan's band list when it needs a penalty. Per-type plan availability was not raised. Engine and agency visibility of a plan is Sprint 20.

## Task 04 · Taxes and fees

### What was built

Business rules gain `taxes`: a list of `{code, label, basis, amount, child_exempt_under_age, charged, shown_in_price_panel}`. Bases are `PER_STAY`, `PER_NIGHT`, `PER_PERSON_PER_NIGHT` and `PCT_OF_ROOM`. The migration publishes the hotel fixture list in local and testing, and an empty list elsewhere, with approval reference `Sprint 18: taxes added (09 H9)`.

`TaxCalculator::forStay` is pure. `PCT_OF_ROOM` is a whole percent of the stay total after discounts, rounded half-up. A child younger than `child_exempt_under_age` is left out of a per-person tax. `charged = false` stays on the quote and is not added to `total_including_charged_taxes`. An empty list produces no tax lines.

A configured city tax renders `City tax · 2 adults × 3 nights`.

`engine_settings.fees.png` and `fees.tct_pp` are legacy and refused on publish. `fees.footnote` can still change. `PngCategory`, `ApplyPng` and `png_collected` stay for yacht bookings. New app code cannot use them; the existing callers are an allowlist on the arch test until Sprint 22.

### Files touched

- `app/Enums/TaxBasis.php`
- `app/Support/Config/Documents/Tax.php`
- `app/Support/Config/Documents/Taxes.php`
- `app/Support/Config/Documents/BusinessRulesDocument.php`
- `app/Support/Config/Documents/BusinessRulesConstraint.php`
- `app/Support/Config/Documents/EngineSettingsDocument.php`
- `app/Support/BusinessRules/Registry.php`
- `app/Services/Pricing/TaxCalculator.php`
- `app/Services/Pricing/TaxLine.php`
- `app/Services/Pricing/StayParty.php`
- `app/Services/Pricing/StayQuote.php`
- `app/Services/Pricing/QuoteLabels.php`
- `lang/en/pricing.php`
- `app/Http/Resources/Rms/BusinessRulesCurrentResource.php`
- `app/Http/Resources/Rms/ConfigVersionDetailResource.php`
- `database/migrations/2026_10_05_150001_add_taxes.php`
- `tests/Unit/Services/Pricing/TaxCalculatorTest.php`
- `tests/Feature/Config/AddTaxesMigrationTest.php`
- `tests/Feature/Config/EngineSettingsDocumentTest.php`
- `tests/Feature/Guests/GuestPngPersistTest.php`
- `tests/Arch/ArchTest.php`
- `docs/sprints/sprint-18/REPORT.md`

### Deviations

`hotel-seed-data.json` has no `taxes` key, so the fixture list is empty. Local, testing and production all publish `[]` until a real list is entered. No amounts were invented.

The booking column `tax_lines` is Sprint 19. This task only puts tax lines on the quote. `RoomPricer` does not read business rules. Task 05 applies `TaxCalculator` when it builds the stay quote.

Child exemption applies to `PER_PERSON_PER_NIGHT` only. The other bases do not count people.

`GuestPngPersistTest` used to republish a PNG amount. That publish is now refused, and the stored guest fee stays as it was.

### Open questions

None beyond the empty fixture list.

### Notes for later

Task 05 should call `TaxCalculator` and attach the lines with `StayQuote::withTaxes`. The panel price check (task 06) should show `shown_in_price_panel` lines and keep uncharged taxes out of the amount due. Sprint 22 removes the PNG allowlist.

## Task 05 · Quoter, price check and config checks

### What was built

`StayQuoter` loads published rates, business rules and engine settings, checks the party against the room type, then prices through `RoomPricer`. A draft rates document replaces only the rates, so price check never publishes. After the room total, `BookingDiscounts::applyToStay` applies the online deposit discount and `max_total_discount_pct`. `TaxCalculator` then adds tax lines, and the plan's cancellation bands go on the quote terms.

`quoteRooms` returns one result per room plus the combined room total, deposit and charged-tax total. Group rules are not applied.

`POST /api/rms/bookings/quote` takes `check_in`, `check_out` and `rooms[]` when `check_in` is present. The departure payload is unchanged.

`POST /api/rms/rates/price-check` takes a draft rates document and an optional stay list. With no stays, the examples are the fixture `reference_quotes`. Each scenario has a published quote, a draft quote and the difference of the room totals. The eight fixture totals (200, 260, 240, 90, 220, 300, 90, 648) come back on the draft quote. The request does not write a rates version.

`StayConfigChecks` warns when an active room type has no rate in a season, when nights in the sale horizon sit in no season, and when a restriction row sits on a night with no rate. Those warnings are on the rates validate response. Yacht year checks stay on `DepartureConfigChecks`.

### Files touched

- `app/Services/Pricing/StayQuoter.php`
- `app/Services/Pricing/StayReservationQuote.php`
- `app/Services/Pricing/StayRoomsQuote.php`
- `app/Services/Pricing/GuestsInvalid.php`
- `app/Services/Pricing/BookingDiscounts.php`
- `app/Services/Pricing/StayQuote.php`
- `app/Services/Pricing/StayQuoteInput.php`
- `app/Services/Config/StayConfigChecks.php`
- `app/Support/Config/Documents/RatesDocument.php`
- `app/Http/Controllers/Rms/BookingController.php`
- `app/Http/Controllers/Rms/RatesController.php`
- `app/Http/Requests/Rms/QuoteReservationRequest.php`
- `app/Http/Requests/Rms/PriceCheckRequest.php`
- `app/Http/Resources/Rms/StayRoomsQuoteResource.php`
- `lang/en/pricing.php`
- `tests/Feature/Pricing/StayQuoteTest.php`
- `tests/Feature/Config/StayPriceCheckTest.php`
- `tests/Feature/Config/StayConfigChecksTest.php`
- `tests/Feature/Config/RatesEndpointsTest.php`
- `tests/Pest.php`
- `docs/sprints/sprint-18/REPORT.md`

### Deviations

Offers and promo codes still match a departure and a cabin category, so the stay path does not apply them. The online deposit discount and the cap do apply, with the same half-up and "reduced to the maximum discount" rule. `StayQuoteInput.promo` is still unused until Sprint 22.

`DepartureConfigChecks` stays. Yacht year errors and the OPS-006 first-cruise row still read departures. `StayConfigChecks` is the stay warnings, and the rates document exposes them.

Price check no longer returns the eight yacht cabin scenarios. The default list is the hotel `reference_quotes`. `year` is no longer an input.

A fixture quote that only has a children count is priced with one child age of `guests.child_min_age` per child, so the age is a published rule and the child still counts.

### Open questions

Meal-plan labels and `guests.infant_max_age` remain `TODO(OPEN: 09 H8)`. Ages below `child_min_age` are refused with the published under-age message.

### Notes for later

Task 06 should read the new price-check shape (`scenarios[].key` is `Q1`…`Q8`, totals on `draft.total`) and show `shown_in_price_panel` tax lines without adding uncharged taxes to the amount due. The public engine quote is still a departure quote. Sprint 19 retires that adapter.

## Task 06 · Panel rates editor and price check

### What was built

The rates page now edits the stay document. Seasons are a table plus a 12-month strip: a month with no covered night is a gap, and a month that is only partly covered is marked partial. Room rates are a matrix of active room types by season. Occupancy, day of week, length of stay, supplements, and rate plans are their own sections. The yacht base rates, deposit terms, and discount rules stay on the page inside a collapsed block titled "Legacy (yacht) — read only", and those inputs stay disabled even for an admin. Publish still sends the whole document, including the frozen yacht fields, and still requires an approval reference.

Field errors come from the rates `validate` endpoint. Soft warnings render with a warning mark under seasons (including stay-restriction warnings), the room-rate matrix, and supplements. The publish bar still lists every warning.

Price check lists stays. With none filled in, the panel posts the draft document and seeds the editors from the fixture examples the API returns. Each stay has a date range, room type, adults, child ages, and plan. A complete stay is sent back so the draft is priced against the published version. The panel shows night lines, summary lines, taxes with `shown_in_price_panel`, the room total, the amount due (`total_including_charged_taxes`), and the difference. A tax that is not charged stays out of the amount due.

Business rules gained a taxes-and-fees editor (code, label, basis, amount, charged, shown in price) and a cancellation-sets editor (named sets of bands). Those rows no longer fall through to a number input.

### Files touched

iconic-panel:

- `app/pages/rms/commercial/rates.vue`
- `app/components/rates/RatesSeasonsPanel.vue`
- `app/components/rates/RatesRoomMatrix.vue`
- `app/components/rates/RatesOccupancyPanel.vue`
- `app/components/rates/RatesStayRulesPanel.vue`
- `app/components/rates/RatesPriceCheck.vue`
- `app/components/rates/stayRates.ts`
- `app/components/rates/rateHelpers.ts`
- `app/components/rules/RulesTaxesEditor.vue`
- `app/components/rules/RulesSetsEditor.vue`
- `app/components/rules/RulesCurrentCell.vue`
- `app/components/rules/rulesHelpers.ts`
- `app/composables/useConfigEditor.ts`
- `app/types/api.ts`
- `app/assets/css/config.css`
- `eslint.config.mjs`
- `i18n/locales/en.json`
- `i18n/locales/es.json`
- `tests/unit/stayRates.test.ts`
- `tests/components/RatesSeasonsPanel.test.ts`
- `tests/components/RatesPriceCheck.test.ts`

iconic-ui:

- `app/types/config.ts`
- `app/types/index.ts`

iconic-api:

- `app/Http/Controllers/Rms/RatesController.php`
- `tests/Feature/Config/StayPriceCheckTest.php`

### Deviations

Price check now returns `input` on each scenario (room type, dates, nights, adults, child ages, plan) so the panel can prefill the fixture stays. Totals and the difference are unchanged. The hand-written mirrors in `iconic-ui` were updated; Scramble still types the document and the scenarios loosely, and the panel does not read the generated price-check request.

Meal plans are the stored codes RO, BB, HB, and FB. The new Spanish strings are the English copy so the locale key check stays even. A new tax keeps `child_exempt_under_age` on the row (null until set) and the editor does not add a control for it.

`pnpm typecheck` still fails on existing yacht fields (`yacht`, `yacht_id`, `date_and_yacht`) and two CRM stage casts. None of those files are part of this task. The new files typecheck. `pnpm lint` on the touched files is clean. `pnpm test` is 324 passed. Stay price-check Pest is 4 passed, including the new `input` fields.

### Open questions

Meal-plan labels and `guests.infant_max_age` remain `TODO(OPEN: 09 H8)`. The plan select shows the codes. Ages below `child_min_age` are still refused by the quoter.

### Notes for later

Task 07 owns the browser scenarios HRATE-01…05. The embedded browser could load the panel login page, then failed to fetch `http://localhost:8000/sanctum/csrf-cookie`, so the rates and business-rules screens were not exercised in the browser. The panel dev server is running at `http://localhost:3001/`.

The public engine quote is still a departure quote. Sprint 19 retires that adapter.

## Task 07 · Sprint close: e2e and report

### What was built

Hotel scenarios HRATE-01 through HRATE-05. INDEX lists them as batch B23. HRATE-01, HRATE-02, and HRATE-03 are P1. HRATE-04 and HRATE-05 are P2.

Retired: RATE-02 and RATE-05 (yacht year edits) and RATE-06 (two editors on those fields), replaced by HRATE-03. RATE-03 (the eight yacht totals), replaced by HRATE-01. RATE-04 (child discounts per cabin) was not in the task list; those cells now sit in the frozen yacht block, so the old steps cannot be walked. The invalid-then-valid check is HRATE-02.

Rewritten: RATE-01. Lucía is still view-only. The price-check check is one hotel stay (`USD 200`, difference `no change`) instead of sailing year 2027. It stays P2.

The price-check note now includes the deposit and the cancellation set, so HRATE-04 can be read on the card. Room total, amount due, and the difference are unchanged.

The P1 set was not run. `tests/e2e/bin/up.sh` was not started. Its first step copies `tests/e2e/environment/api.env` over `iconic-api/.env`. Host ports 8000 and 8001 belong to `keevaris-api`. The working API containers already use the names `iconic-api`, `iconic-mysql`, `iconic-redis`, and `iconic-mailpit`, and mailpit already owns 8025. Port 3000 has a listener on `[::1]`. Port 3001 is the panel dev server from task 06, still running. Memory available was 10.1 GiB, above the 6 GiB warning. Detail: `tests/e2e/runs/2026-10-05-1810-sprint-18-p1.md`. Ledger: `tests/e2e/runs/LEDGER.md`. INDEX has 102 P1 rows. 0 passed, 0 failed, 102 not run.

### Sprint summary

A stay is priced per night from the rates document: seasons, room rates, occupancy, weekday, length of stay, supplements, and rate plans. `RoomPricer` matches the eight hotel reference totals. Taxes are a list on business rules; an uncharged tax can be shown and left out of the amount due. The panel edits that document, runs the price check, and keeps the yacht years read-only until Sprint 22. Existing bookings keep the price lines they were sold with.

### Accepted reasons (P1 did not pass)

- **ENV.** The e2e stack cannot start beside `keevaris-api` and the working iconic containers without replacing `.env` and colliding on container names and ports 8000, 8001, and 8025.
- **Seed.** HRATE-01 through HRATE-05 require hotel seed. `tests/e2e/environment/api.env` does not set `ICONIC_SEED_MODE=hotel`. The default remains yacht until Sprint 19.

### Files touched

- `tests/e2e/scenarios/hotel/HRATE-01-price-check-reference-stays.md` (new)
- `tests/e2e/scenarios/hotel/HRATE-02-overlapping-seasons-refused.md` (new)
- `tests/e2e/scenarios/hotel/HRATE-03-add-a-season-and-publish.md` (new)
- `tests/e2e/scenarios/hotel/HRATE-04-rate-plan-deposit-and-cancellation.md` (new)
- `tests/e2e/scenarios/hotel/HRATE-05-city-tax-shown-not-charged.md` (new)
- `tests/e2e/scenarios/config/RATE-01-rates-read-only.md` (rewritten)
- `tests/e2e/scenarios/config/RATE-02-year-helpers.md` (retired)
- `tests/e2e/scenarios/config/RATE-03-price-check-reference.md` (retired)
- `tests/e2e/scenarios/config/RATE-04-invalid-then-valid.md` (retired)
- `tests/e2e/scenarios/config/RATE-05-publish-and-history.md` (retired)
- `tests/e2e/scenarios/config/RATE-06-two-editors.md` (retired)
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/LEDGER.md`
- `tests/e2e/runs/2026-10-05-1810-sprint-18-p1.md` (new)
- `docs/sprints/sprint-18/README.md`
- `docs/sprints/sprint-18/REPORT.md`
- `iconic-panel` `app/components/rates/RatesPriceCheck.vue`
- `iconic-panel` `i18n/locales/en.json`
- `iconic-panel` `i18n/locales/es.json`
- `iconic-panel` `tests/components/RatesPriceCheck.test.ts`

### Deviations

`up.sh` was not executed. Running it would replace the local `.env` and fight containers that are already up.

The price-check card gained a deposit line and a cancellation set. HRATE-04 cannot be checked from the room total alone: changing the deposit does not change that total. The component test covers the new sentence.

`composer check` was not re-run. This task did not change PHP.

### Open questions

Meal-plan labels and `guests.infant_max_age` remain `TODO(OPEN: 09 H8)`. HRATE-04 leaves the meal plan as the stored code `RO`. Ages below `child_min_age` are still refused. The Q3 child age in HRATE-01 is that published minimum (6 in the engine-settings initial document), not an infant price.

### Notes for later

- Bring the e2e stack up only when ports 8000, 8001, 8025, 3000, and 3001 are free and replacing `iconic-api/.env` is acceptable. Do not stop `keevaris-api` to free 8000.
- Set `ICONIC_SEED_MODE=hotel` before `reset.sh` when walking HRATE. Sprint 19 is the switch of the default seed.
- The public engine quote is still a departure quote. Sprint 19 retires that adapter.
- Panel `nuxt typecheck` still fails on older `yacht` reads. See task 06.

### Git commands for the user

Nothing was committed. Tasks 01–06 are still uncommitted in `iconic-api`, `iconic-ui`, and `iconic-panel`. Review `git status` in those repos before adding those files. The commands below are only this task.

```bash
cd iconic-api
git add \
  docs/sprints/sprint-18/README.md \
  docs/sprints/sprint-18/REPORT.md \
  tests/e2e/scenarios/INDEX.md \
  tests/e2e/scenarios/hotel/HRATE-01-price-check-reference-stays.md \
  tests/e2e/scenarios/hotel/HRATE-02-overlapping-seasons-refused.md \
  tests/e2e/scenarios/hotel/HRATE-03-add-a-season-and-publish.md \
  tests/e2e/scenarios/hotel/HRATE-04-rate-plan-deposit-and-cancellation.md \
  tests/e2e/scenarios/hotel/HRATE-05-city-tax-shown-not-charged.md \
  tests/e2e/scenarios/config/RATE-01-rates-read-only.md \
  tests/e2e/scenarios/config/RATE-02-year-helpers.md \
  tests/e2e/scenarios/config/RATE-03-price-check-reference.md \
  tests/e2e/scenarios/config/RATE-04-invalid-then-valid.md \
  tests/e2e/scenarios/config/RATE-05-publish-and-history.md \
  tests/e2e/scenarios/config/RATE-06-two-editors.md \
  tests/e2e/runs/LEDGER.md \
  tests/e2e/runs/2026-10-05-1810-sprint-18-p1.md
git commit -m "$(cat <<'EOF'
Record sprint 18 close and the nightly-rate scenarios.

EOF
)"
```

```bash
cd iconic-panel
git add \
  app/components/rates/RatesPriceCheck.vue \
  i18n/locales/en.json \
  i18n/locales/es.json \
  tests/components/RatesPriceCheck.test.ts
git commit -m "$(cat <<'EOF'
Show deposit and cancellation on the stay price check.

EOF
)"
```

