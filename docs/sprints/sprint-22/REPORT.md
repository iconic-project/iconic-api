# Sprint 22 report

## 22-01 — Offers and promos on stay windows

An offer now matches a stay. The discount applies to each night inside `[stay_from, stay_to]` (last night inclusive). The stay must meet `min_nights`. The booking date must sit in the existing booking window. Empty room-type and rate-plan lists mean all. A null stay window means any night.

The discount is a summary line and a `discount` on each eligible night line. Night `total` stays gross. The quote total is net. The combined cap `max_total_discount_pct` still applies to the stay total and trims the night discounts to the same amount. Percent is of each eligible night. A fixed amount is one pool for the stay (a promo multiplies the value by guests) and is spent across eligible nights in order. Credit and value-add stay a zero line. Commission is not applied on a stay. The result is frozen on the booking's night lines and price lines.

The engine promo check takes check-in, check-out, an optional room type and an optional rate plan. The price calendar's `from_price` is the lowest net night when a live public price offer exists, and each night lists the badge texts for that price. Availability lists `{code, badge}` on each room type when the winning quote carries a badge offer.

Migration `2026_10_06_130001_add_stay_window_to_offers` adds the columns, makes `cabin_types` and `itinerary_codes` nullable, and backfills stay windows from the union of matching departure stays. When no departure matches, the travel dates are the fallback. The demo seeder runs the same backfill after insert, so a public percent with a travel date does not become an all-nights offer.

The panel editor uses a stay range (check-out is the morning after `stay_to`), min nights, room types and rate plans. Approval is unchanged. The staff quote sends `main_channel` so the preview uses the same channel as the sale.

### Files

- Schema and backfill: `2026_10_06_130001_add_stay_window_to_offers`, `BackfillOfferStayWindows`, `DemoOffersSeeder`
- Offer model and editor path: `Offer`, `OfferFields`, `OfferGuardrails`, `OfferPresentation`, `OfferResource`, `CreateOffer`, `ValidatesOfferFields`, `PromoCode`
- Pricing: `BookingDiscounts`, `NightLine`, `StayQuote`, `StayQuoteInput`, `StayQuoter`, `DepartureOffers`, `CreateStayReservation`, `QuoteReservationRequest`, `BookingController`, `FreezeCommission`
- Engine: `EnginePromoCheck`, `PromoCheckController`, `CheckPromoRequest`, `EngineCalendar`, `EngineStayAvailability`
- Net of discount: `HotelKpis`, `DocumentFacts`, `ModifyStay`
- Panel: `offers.vue`, `OfferDrawer.vue`, `offerHelpers.ts`, `StayReservationModal.vue`, `en.json`, `es.json`
- Types: `iconic-ui` `api.d.ts` (`OfferResource`, `StoreOfferRequest`, `UpdateOfferRequest`)
- Tests: `StayOfferWindowTest`, offer, pricing, engine and OpenAPI updates
- E2E: HOFF-01, HOFF-02, OFF-01, OFF-03, `INDEX.md`

### Deviations

- Yacht matching stays in `DepartureOffers`. `Offer` no longer references `Departure`. `EnginePromoCheck::check` still takes a `Departure` for the yacht promo body. That path goes in task 04.
- `AnkStayInput` `maxNights` on the offer window is 366. That is a picker limit, not a commercial rule. Guest `stay.max_nights` is too short for a season.
- A null stay window means any night, same as a null travel window did.
- Commission offers are not applied on stays. Stay commission remains the agency percent.
- Early departure credits unused nights net of `discount`. It does not recompute the offer summary line.
- Scramble was not regenerated. The layer's `api.d.ts` was patched for the new offer fields.

### Open questions

None.

### Notes for later

- Drop `cabin_types`, `itinerary_codes` and `travel_from` / `travel_to` in task 03. The backfill reads the `departures` table until then.
- The public engine page does not render the new night `offers` list yet. The API returns it. Task 05 owns the yacht pages.
- `EngineFeed` still matches offers to departures. Task 04 removes that path.
- Panel `pnpm typecheck` still fails on yacht fields (`yacht`, `yacht_id`) that the generated departure types no longer have. Those files are outside this task.
- `php artisan test` still fails outside this task: demo agency count, engine-settings copy (`Anakata` vs `Iconic`), automation catalogue size, cabin-claim locks, and the `png_collected` arch allowlist. Offer, pricing, engine stay, and OpenAPI schema tests pass. The OpenAPI 500 in the full run was a file write during that run; a re-run of those two schema tests passed.

## 22-02 — Buyout dropped (HQ1)

HQ1 was still open. The choice for this task was to drop exclusive use. Path A (buyout type, claim every room, `buyout.vue`, HBUY-01) was not built. `buyout.enabled` was not in the engine-settings document, so it was not added. The enquiry and proposal routes return 410 instead of hiding behind a flag.

`POST` and `GET` on these paths return `{ "message": "This endpoint has been removed." }` with status 410, including a guest on the RMS paths:

- `/api/rms/charter-enquiries`
- `/api/rms/charter-enquiries/{enquiry}`
- `/api/rms/charter-enquiries/{enquiry}/proposal`
- `/api/engine/charter-enquiries`
- `/api/engine/charter-proposal/{token}`
- `/api/engine/charter-proposal/{token}/accept`
- `/api/engine/charter-proposal/{token}/decline`

The routes sit in `routes/api.php`, ahead of the authenticated RMS group. Write actions, the enquiry event, listeners, mails, the deposit command and its schedule, the policy, the controllers, requests and resources that only served those routes, and the proposal views are gone. The panel charter panels and the engine `charter` and `charter-proposal` pages are gone. A private-charter calendar row uses the existing contact mail link.

Existing enquiry rows stay readable. `CharterEnquiry`, its factory, `CharterEnquiryStatus`, `CharterEnquirySource`, and `BookingType::Charter` stay. Tables are not dropped. `CharterProposalState` is gone because only the removed resource used it.

Migration `2026_10_06_170001_remove_charter_config_keys` publishes a new business-rules version and a new engine-settings version when the current JSON still has a `charter` group. The approval reference is `Sprint 22: charter keys removed (exclusive use dropped, HQ1)`. The actor is System. `down()` is empty. Both documents keep an optional `charter` array only so an already published group can be loaded and then omitted. `initial()` and `rules()` do not include it. After the migration, the current documents have no `charter` key. Cancellation `charter_bands` stay.

Scenarios OFF-04, BKG-04, PIPE-04 and ENG-04 are deleted, including their INDEX rows. ENG-02 no longer asks for the private charter page. HBUY-01 was not added. OFF-04 also covered the waitlist landing in the RMS. The waitlist API tests remain in `EngineWaitlistCharterTest`.

### Files

- 410: `RetiredEndpointController`, `routes/api.php`; charter routes removed from `routes/api/rms.php` and `routes/api/engine.php`
- Config: `2026_10_06_170001_remove_charter_config_keys`, `BusinessRulesDocument`, `EngineSettingsDocument`, `Registry`, `EngineSettingsResource`, `EngineSettingsCurrentResource`, `ConfigVersionDetailResource`
- Removed write path: `app/Actions/Charter/*`, `OpenDealForCharterEnquiry`, `CharterEnquiryReceived`, both listeners, `CharterEnquiryMail`, `CharterProposalMail`, `CharterDepositsCommand`, `CharterDepositClock`, `CharterRules`, `CharterSettings`, `CharterEnquiryPolicy`, the charter controllers, requests, resources, `CharterProposalState`, the proposal and enquiry views
- Still registered, no longer sent: `AutomationCatalogue` rows `charter_proposal` and `charter_enquiry` are `missing()`. `DocumentView` and `DeliveryMailFactory` throw if a proposal is rendered or mailed. `TaskSweep::charterLeft` still closes an existing charter-quote task. `AppServiceProvider` still morphs `charter_enquiry`
- Panel: `booking-requests.vue`, `settings.vue`, `engineSettingsHelpers.ts`, `canEditPath.test.ts`. Deleted `CharterEnquiriesPanel.vue`, `charterActions.ts`, `EngineCharterPanel.vue`
- Engine: `engineFlow.ts`, `DepartureActions.vue`, `nuxt.config.ts`, `app/types/api.ts`. Deleted `charter.vue`, `charter-proposal/[token].vue`, `charterGuests.ts`, `charterProposal.ts`
- Types: `iconic-ui` `offers.ts` and `index.ts` no longer export the enquiry and proposal aliases. `iconic-panel` `app/types/api.ts` no longer re-exports them. `api.d.ts` was not regenerated
- Tests: `CharterRoutesGoneTest`, `RemoveCharterConfigKeysMigrationTest`, and the config, CRM, pipeline, waitlist and OpenAPI updates listed above
- E2E: deleted OFF-04, BKG-04, PIPE-04, ENG-04. Updated ENG-02 and `INDEX.md`

### Remaining `charter` hits in `app/` and `routes/`

410 path strings: `routes/api.php`.

Read-only enquiry history: `Models/CharterEnquiry.php`, `Enums/CharterEnquiryStatus.php`, `Enums/CharterEnquirySource.php`, `AppServiceProvider` morph map, `Models/CrmTask.php`, `Models/Deal.php`, `Models/Document.php`, `Models/BookingAccessToken.php`, `Actions/Crm/RaiseTask.php`, `Support/Crm/TaskSweep.php`, `Enums/TaskKind.php`, `Support/Crm/DealStageMap.php`, `Support/Crm/ContactReferences.php`, `Enums/BookingAccessTokenPurpose.php`.

Config passthrough so an old published group can be removed: `BusinessRulesDocument`, `EngineSettingsDocument`.

Catalogue and delivery leftovers that name the retired product and do not send it: `AutomationCatalogue`, `DeliveryMailFactory`, `DocumentView`, `DeliverySubject`, `DocumentMail`, `SnapshotFactory`, `DocumentFacts`, `Enums/DocumentKind.php`, `Enums/DeliveryKind.php`, `Enums/AlertKind.php`, `AlertRegistry`, `Support/Engine/PagePath.php`, `Enums/BehaviouralEventName.php`, `Support/Engine/BehaviouralEventParams.php`, `Enums/ConsentDocument.php`.

Yacht sale path, left for task 04: `BookingType::Charter` and every rate, quote, cancellation, manifest, metric, calendar and agency use of `charter_week`, charter deposit terms, `charter_bands`, `dpng_charter_days`, and the charter booking type. That includes `RatesDocument`, `RateYear`, `RateTerms`, `RateRules`, `ManifestsRules`, `QuoteTerms`, `CabinPricer`, `StayQuote`, `QuoteReservationRequest`, `CreateReservation`, `MoveBooking`, `CancellationBands`, `CreateRefundRequest`, `CommercialMetrics`, `Availability`, `EngineLabel`, `ManifestDue`, `ManifestRow`, `ManifestRoster`, `PaymentsKpis`, `PaymentController`, `PortalPreview`, `Registry` (`fin-003-charter-deposit` and the charter-band leaf), and the RMS resources that echo those fields (`RatesCurrentResource`, `ReservationQuoteResource`, `EngineQuoteResource`, `EngineRatesResource`, `AgencyResource`, `RefundRequestResource`, `ManifestDepartureResource`, `BusinessRulesCurrentResource`, `ConfigVersionDetailResource`).

### Deviations

- `CharterEnquiryStatus` and `CharterEnquirySource` stay. The model casts stored rows with them. The task said to remove enums. `CharterProposalState` was removed.
- `buyout.enabled` was not added. The setting was not in the document. Removing the pages and returning 410 is the hide.
- The optional `charter` property stays on the two config documents. `ConfigPublisher` refuses a publish when the loaded document and the submitted document are equal. Dropping the property inside `fromArray` would hide the key and the migration could not publish. `initial()` does not emit the key.
- Re-running `2026_09_23_120005_add_charter_rules_to_business_rules` on the current document publishes the enquiry group again, because the passthrough keeps it. The new migration then removes it. The historical migration was not edited.
- Yacht charter pricing, `BookingType::Charter`, and cancellation set `CHARTER` stay until task 04.
- OFF-04 was the charter enquiry plus the waitlist landing. Deleting the scenario removes the waitlist half. Waitlist feature tests stay.
- Scramble was not regenerated. Stale charter schemas remain in `iconic-ui` `app/types/api.d.ts`. The hand aliases that pointed at them were removed.

### Open questions

None. HQ1 was answered as drop for this task.

### Notes for later

- Task 03 decides archive tables. Do not drop `charter_enquiries` here.
- Task 04 removes the yacht sale vocabulary listed above, including `BookingType::Charter`.
- Task 05 regenerates OpenAPI types. That drops the stale charter schemas in `api.d.ts`.
- The automation catalogue test still expects 58 rows and sees 61. Moving `charter_proposal` and `charter_enquiry` from built to missing does not change the row count. That failure was already present.
- `EngineSettingsSeederTest` still fails on `details_note` (`Anakata` in seed data, `Iconic` in `initial()`). The new `charter` key assertion sits after that line.
- `PanelResponseSchemasTest` sprint 11 still fails because `ManifestIssuedResource` is absent from the generated spec. No controller returns that resource. Sprint 12 in the same file passes.
- Live check: `http://127.0.0.1:18000` returns 410 and the removal message for all seven paths. The panel at `127.0.0.1:3001` calls `http://localhost:18000`. Sign-in from that page left the button disabled, same cross-host CSRF failure as before. The engine dev server was not on port 3000. The charter pages are deleted from `iconic-engine/app/pages`.

## 22-04 — Remove legacy code, enums and config keys

Yacht inventory code is gone from the live application. The seeder always writes Hotel Demo. Rates, engine settings and business rules publish a new version when a stored document still has a yacht key. Role JSON drops `departures.manage` and `itineraries.manage`. The vocabulary test is the gate for the retired words.

`show_on_departures` is now `show_on_calendar`. `days_before_departure` on a refund request is `days_before_arrival` (days before check-in). Stored alert kinds and behavioural event values are unchanged; the PHP names no longer contain the retired word as one token. Entry-fee and TCT amounts are no longer read from engine settings. A collected TCT with no frozen rate stores 0. Document payment lines use `stay_balance_amount` and `stay_received`.

### Files

- Vocabulary: `tests/Arch/VocabularyTest.php`. The temporary PNG allowlist is gone from `tests/Arch/ArchTest.php`.
- Config: `2026_10_06_190001_remove_legacy_config_keys.php`, `RemoveLegacyConfigKeysMigrationTest`
- Permissions: `2026_10_06_190002_remove_legacy_permissions.php`, `RemoveRetiredPermissionsMigrationTest`
- Columns: `2026_10_06_180004_rename_show_on_departures_and_days_before_departure.php`
- Seed: `DatabaseSeeder` (hotel only), `config/iconic.php`, README seed section
- Fees and documents: `UpdateBookingFees`, `BookingExtraController`, `DocumentFacts`, `resources/views/documents/partials/schedule.blade.php`
- Migration class kept: `CopyPublishedItineraryContent` (queried through the table, not the deleted model)
- Offer column callers, alert kinds, behavioural event cases, and the catalogues that named a departure date as check-in

### Deviations

- `CopyPublishedItineraryContent` stays. Migration `2026_10_05_210001` resolves that class. The class name is the one non-archive hit for `itinerary`. The body queries the old table with a split string. `handle()` copies highlights only when that table still exists, which is true while the migration runs and false after the archive rename.
- Backfill classes and `HotelContractCheck` stay. Merged migrations call them. They are allowlisted.
- `closed_to_departure` stays. It is the H7 guest-leaving restriction.
- The word departures in front-desk code stays. It means guests checking out.
- Response keys that are the stay check-in (`departure_date`, `age_at_departure`) stay, with a reason on the allowlist. Task 05 updates the clients.
- Journey anchors `departure` and `departed`, and the check-in fact, stay as stored values. The source splits the token.
- `cancellation.charter_bands` was not removed. Task 22-02 kept that set.
- `ICONIC_SEED_MODE` still loads from the environment and is ignored. An old env file does not change the seed.
- Yacht-only tests that imported deleted classes were deleted (`CabinPricerTest`, departure and itinerary endpoint tests, engine feed, manifests, voyage status, offer-applicable, the yacht `OfferPricingTest`, demo inventory and demo booking seeder tests, and the two reservation concurrency tests). Hotel price coverage remains in `RoomPricerTest` and `StayPriceCheckTest`.

### Open questions

None.

### Notes for later

- `composer check` is not green. PHPStan on `app/` is clean. `VocabularyTest` passes. The two new migration tests pass. `php artisan migrate:fresh` on `iconic_test` completes, including the new migrations. A set of feature tests still constructs the deleted yacht types (`Departure`, `Itinerary`, `Availability`, `PngCategory`, `CabinCategory`). They are listed by `rg` of those imports under `tests/`. Shared helpers `tests/Support/Bookings/ReservationFixtures.php` and `tests/Support/Offers/OfferFixtures.php` are in that set, and so are the yacht demo seeders under `database/seeders/`. Those seeders are no longer called from `DatabaseSeeder`.
- Task 05 regenerates OpenAPI types and should switch the remaining `departure_date` response keys to check-in.
- The offer stay-window backfill test that built two departures was removed. The backfill still runs inside `2026_10_06_130001`, before the archive rename.

## 22-05 — Frontends cleanup

The four frontends now follow the hotel API. `iconic-ui` is 0.18.0. Generated types no longer include departure, cabin, itinerary, yacht, or manifest aliases. Hotel aliases are property, room, room type, and stay.

The panel no longer has yacht layout, departure, itinerary, or manifest screens. The booking-engine menu has no departures item. Legacy rate year, term, and rule panels are gone. Offers use show on calendar. Engine settings guests use max per property. Fees are the price-panel flag and the footnote. Business rules use the check-out field names and no longer edit manifests.

The public engine keeps `/`, `/rooms/[slug]`, `/book/rooms`, `/book/details`, and `/book/confirmation`. Nitro 301s send `/itineraries` and `/itineraries/**` to `/rooms`, and `/book/cabins` to `/book/rooms`. Confirmation polls the stay checkout status.

A panel ESLint rule (`vocabulary/no-retired-vocabulary`) blocks the same retired words as the API arch test, over `app/`. Allowlisted files are the front desk (guests leaving), `closed_to_departure`, `image/png`, stored history event names, and API fields that still use the old names (`age_at_departure`, `departure_date`, the `png` fee code, the stored sales-material value). A Vitest in each repo walks locale JSON and fails on the words yacht and cabin.

### Files

- Layer: `iconic-ui` `api.d.ts` regenerated, hand aliases in `inventory.ts`, `engine.ts`, `config.ts`, `guests.ts`, `documents.ts`, `bookings.ts`, `index.ts`; `package.json` 0.18.0; `CHANGELOG.md`; `README.md`
- Panel: deleted yacht pages and helpers; stay overview on `BookingPanel.vue`; rates, rules, engine settings, offers, holds, bookings, guest experience, reports; `eslint/vocabulary.mjs`; locale JSON; `tests/unit/retiredWords.test.ts`
- Engine: deleted itinerary, cabin, and charter pages; `nuxt.config.ts` redirects; `confirmation.vue`, complete, questionnaire, survey; locale JSON
- Portal: room class names on the new-request form; booking status tones for in-house and checked-out
- READMEs in all four repos

### Deviations

- Some API fields still use the old names. The panel reads them and the ESLint rule allowlists those files. The clients do not rename the API.
- `charter` is not in the vocabulary pattern. Payment KPI copy still shows the charter deposit percent the API returns.
- Locale JSON was rewritten in place. Unused departure and yacht-layout trees were removed. Live sentences now say room or stay.
- The style-guide playground labels say Property, Room type, and Check-in.

### Open questions

None.

### Notes for later

- Guest extras still describe the `png` fee, because the API still returns that fee code.
- History rows for old itinerary and departure events still have those event names. `describe.ts` keeps the cases so old ledger rows stay readable.
- The signed-out panel sends `/rms/booking-engine/departures` to login. Engine and portal were not running, so the 301s were not clicked in a browser.
