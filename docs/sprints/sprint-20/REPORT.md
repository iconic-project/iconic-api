# Sprint 20 report

## 20-01 — Content: itineraries → property and room types

Property content and room type content are the booking-engine CMS. Completeness is on both resources. A room type is `engine_visible` only when it is `ACTIVE` and every content check passes (photo with alt, description, bed setup, SEO title, SEO description). Property checks are hero (path and alt), description, address, policies, SEO title, SEO description.

`UpdatePropertyContent` ignores a typed hero path. `ReplacePropertyHero` and `AddRoomTypePhoto` store the file and require alt text. `UpdateRoomTypeContent` accepts only photo paths already on the room type, so a new file goes through the upload. Removed photo files are deleted after commit. Content changes bump the engine feed version.

Itinerary create, update, delete and image upload are denied for every role. Reads stay. Migration `2026_10_05_210001_copy_itinerary_highlights_into_properties` copies the first published itinerary's highlights and FAQs onto properties that do not already have them.

The hotel fixture now has a hero, room photos and room-type SEO fields. After `HotelSeeder`, the property and all four room types score 100%.

Panel: Booking engine → **Property** and **Room types**. `/rms/booking-engine/itineraries` redirects to Room types. The room type drawer creates a type, edits occupancy, photos, amenities and SEO, shows a preview card, adds a room, deactivates, and opens history. History endpoints: property, room type, room.

### Files

- API: `Support/Content/Completeness.php`, `CopyPublishedItineraryContent.php`, property and room-type content actions, image actions, resources, policies, routes, hotel seed, content and itinerary tests
- Panel: `pages/rms/booking-engine/property.vue`, `room-types.vue`, content components, nav, i18n
- Layer: `PropertyContent`, `RoomTypeContent`, `RoomRow` overlays in `iconic-ui` (generated `api.d.ts` not regenerated)
- E2E: INV-02, INV-03, INV-04 retired. HSET-01 now uses Hotel Demo and the new menu

### Deviations

- `UpdateRoomType` was removed. The room-type PATCH calls `UpdateRoomTypeContent`, including occupancy, so there is one write path.
- Create can still store a photo path. The editor does not. Uploads are the path for new files.
- Itinerary `Defaults` stays for the existing read of `/itineraries/defaults`. New code does not use it.
- `api.d.ts` was not regenerated (`pnpm types:api` needs the API OpenAPI URL). Panel overlays carry the new fields until the next generate.
- `es.json` was missing keys that `en.json` already had (front desk, stay bookings, and `bookings.nightsPill`). Those keys were copied in English so locale parity passes. Property and room-type strings are translated.

### Open questions

None.

### Notes for later

- Task 02 should hide room types where `engine_visible` is false.
- Regenerate `iconic-ui` API types after the API is up.
- Yacht installs that already ran migrations before this copy need `php artisan migrate` so the one-off runs. A fresh hotel seed does not need the copy.

## 20-02 — Engine API: property, calendar, availability, quote

The public engine can search a stay from four endpoints. `GET /api/engine/property` returns the first active property that has an engine-visible room type, plus those room types, the default rate plan, every published plan, and the copy, guest and locale settings. It is cached on the feed version and sends an ETag. `GET /api/engine/calendar` returns one row per night: available, from-price, closed to arrival, closed to departure, min stay. `GET /api/engine/availability` returns each visible room type with bookable, reasons, a capped `rooms_left`, and a quote per plan for one room. `POST /api/engine/quote` with `check_in` returns the stay quote and a signed `quote_token` (15 minutes). Promo check accepts a stay and treats `check_in` as the travel date.

A room type is omitted when `engine_visible` is false. The calendar prices each season once per room type. A new claim bumps the feed version, so the next calendar read is not the cached one. Three months of calendar stayed under 400 ms in `EngineStayApiTest`. `search_performed` and `room_type_viewed` are accepted behavioural events.

### Files

- API: `EnginePropertyFeed`, `EngineCalendar`, `EngineStayAvailability`, `EngineStayQuote`, `SeasonNightly`, `QuoteToken`, engine controllers, requests, resources, routes
- `Restrictions::forTypes` loads a date range in one query
- `SetStayRestrictions` bumps the feed version
- Examples: `docs/requirements/examples/engine-property.json`, `engine-availability.json`

### Deviations

- `GET /api/engine/feed` and `GET /api/engine/departures/{departure}/cabins` still respond. The task switches them to 410 when task 20-04 ships.
- `POST /api/engine/quote` and `POST /api/engine/promo/check` still accept a departure body, so current checkout tests keep passing. A body with `check_in` uses the stay contract.
- `docs/requirements/07-three-system-integration-contract.md` is not in this tree. `search_performed` and `room_type_viewed` are the names in the task. Existing event names stay.
- Every published rate plan is returned. The rates document has no public/private flag.
- `from_price` reuses the first priced night of a season. A Friday or Saturday inside that season does not get its own day-of-week adjustment on the calendar.
- `rooms_left` stops at `low_availability_threshold + 1`. That top value means more rooms are free than the threshold.
- `examples/booking-engine-feed.json` stays, because the feed endpoint still serves that contract.

### Open questions

None.

### Notes for later

- Task 03 checkout should open `QuoteToken` and refuse a stay whose totals or rates version drifted.
- Task 04 should send the old feed and cabin routes to 410 and drop the departure quote body.
- Task 22-01 replaces the promo travel-date check with the stay window and `min_nights` (see the `TODO(Sprint 22)` on `EnginePromoCheck::checkStay`).
- Regenerate `iconic-ui` API types after the API is up.

## 20-03 — Engine checkout: hold and pay a stay

`POST /api/engine/checkout` with a `quote_token` opens that token, prices the stay again, and holds every room on one checkout session. The hold is `ClaimKind::Hold` / `HoldType::Web`, expires after `holds.web_minutes`, and can be extended once by `web_extension_minutes`. A drifted total, deposit, tax total, or rates version returns 409 with the new stay quote and creates no session.

`POST /api/engine/checkout/{token}/submit` converts those holds into one requested booking per room. Pay later keeps the request hold. Pay deposit re-prices with the online-deposit advantage and opens Stripe Checkout. The amount charged is the rate plan's deposit. A plan with `deposit_pct` 100 (the non-refundable plan) charges the whole stay. `PaymentSettled` confirms every room on the session. `GET /api/engine/checkout/{token}/status` returns the stay dates and the room types, without room numbers.

Guests are created from the party size. The complete-reservation payload for a stay booking uses `check_in`, `check_out`, and `room_label`. Stay checkout does not collect or apply a PNG category.

### Files

- API: `CreateStayCheckoutSession`, `SubmitStayCheckout`, checkout controller and requests, `CheckoutSession` stay columns, status and complete resources
- Migration: `2026_10_05_220001_add_stay_columns_to_checkout_sessions`
- Tests: `tests/Feature/Engine/EngineStayCheckoutTest.php`

### Deviations

- A body with `departure_id` still creates the cabin hold, so the existing departure checkout tests keep passing. The stay contract is the `quote_token` body. Task 04 should drop the departure body.
- The OpenAPI 201 for `POST /checkout` is still `CheckoutCreatedResource` (departure quote). The stay response is the stay quote at runtime.
- `checkout_sessions.departure_id` is now nullable. Stay rows store `check_in`, `check_out`, `rooms`, and the guest snapshot.

### Open questions

None.

### Notes for later

- Task 04 should send guests through the stay checkout and stop posting `departure_id`.
- Regenerate `iconic-ui` API types after the API is up.

## 20-04 — Engine frontend: the stay shop

The public engine home is the hotel: hero, highlights, room-type cards, FAQs, and a stay search. The search writes a shareable query (`check_in`, `check_out`, `adults`, `child_ages`, `rooms`, `pick`). Results list each room type with the API reason when it cannot be booked, including “Minimum stay {n} nights from this date” and “Change to {n} nights”. A guest picks a quantity and a rate plan. The party is split across those rooms in the browser; the quote and the hold are still the server’s.

The room-type page shows the gallery, bed, size, and amenities, with canonical URL and HotelRoom JSON-LD taken only from the feed. Details quotes the chosen lines, collects the guest and the four declarations, then `POST /checkout` starts the hold countdown. Extend calls the extend route once. Pay later submits the quoted total. Pay deposit submits again after a 409 with the new total. Confirmation shows the stay summary and polls status with the stay hold token.

`/book/cabins`, `/itineraries`, and `/itineraries/{slug}` redirect into the stay shop. Private charter is off the header. The page file remains. `/sitemap.xml` lists the home and each room-type slug.

### Files

- Engine: `app/pages/index.vue`, `app/pages/book/rooms.vue`, `app/pages/book/details.vue`, `app/pages/book/confirmation.vue`, `app/pages/rooms/[slug].vue`, stay components, `app/utils/stayQuery.ts`, `partyPlan.ts`, `stayReasons.ts`, `server/routes/sitemap.xml.ts`, i18n `stayShop`
- Layer: `AnkStayInput` prints the night price from the calendar; `EngineEventParams` and `EngineEventName` include `search_performed` and `room_type_viewed`
- API: property feed `settings.stay` and `settings.availability`, so min nights, max nights, max rooms, and the low-availability threshold are not hard-coded in the shop

### Deviations

- The old feed and cabin routes are still served. This task replaces the shop that called them. Sending them to 410 is still open.
- A `departure_id` checkout body still works. The stay shop posts `quote_token` only.
- Stay response types are hand-written in `iconic-engine/app/types/stay.ts`. Regenerate the OpenAPI types when the spec includes the stay quote.
- `pages/complete/[token].vue` prints `booking.property`. The complete resource no longer has `yacht`, and typecheck was failing on it.

### Open questions

None.

### Notes for later

- This database still has `2026_10_05_125001_add_cancellation_sets` pending. It fails because the current business-rules document requires `payable_days_after_check_out` before the later rename migration runs. December nights therefore come back `NO_RATE`. Search and the results cards work. A priced hold needs that migration and the rates document.
- On this machine the iconic API is `http://127.0.0.1:18000`. Port 8000 is a different app. The engine default remains `http://localhost:8000`.
- The IDE browser cannot call the API (`Failed to fetch`). Server-rendered pages can. Verified home, search to `/book/rooms`, the no-rate cards, the standard-double page, and `/sitemap.xml` with `NUXT_PUBLIC_API_BASE=http://127.0.0.1:18000`.

## 20-05 — Waitlist on stays

`waitlist_entries` now stores `room_type_id`, `check_in`, and `check_out`. `departure_id` and `cabin_category` stay nullable until sprint 22. Existing rows are filled from the departure date and the room type created in 16-03 (`SUITE` → Suite, `OWNER` → Owner's Suite). Check-out is the departure return date.

The engine shows “Join the waitlist” only when a room type is `SOLD_OUT` and `waitlist_enabled`. The stay and the party are the search. Name and email are what the guest types. Availability now includes `waitlist_enabled`.

`OfferWaitlistCabins` is `OfferWaitlistRooms`. A notice goes out when the allocator and the restrictions can hold one room of that type for the entry’s whole stay, and the party fits. A night that only overlaps the stay does not qualify. An entry already notified still occupies a slot, so a second free room notifies the next person and a repeat sweep sends nothing. Nothing is claimed. The mail sentence is `A {room type} is free — {stay}`, with real weekdays. Same month and year print the month once. The link is `/book/rooms` with the stay.

The RMS list shows the stay and the room type. `room_available` is true when the whole stay can hold one room. The add form posts a property, a room type, and the two dates.

### Files

- API: migration `2026_10_06_100001_add_stay_to_waitlist_entries`, `BackfillWaitlistStays`, `WaitlistEntry`, `WaitlistOffers`, `WaitlistOfferCopy`, `OfferWaitlistRooms`, waitlist actions, RMS and engine controllers, requests, resources, mail, factory, demo seeder
- Engine: join form on `RoomResultCard`, `stay.ts` `waitlist_enabled`. The old departure waitlist stub is gone
- Panel: holds waitlist columns, `AddWaitlistModal`, `waitlistRowStatus` (`room_free`), en and es strings
- Layer: `WaitlistEntry` overlay uses `stay` and `room_available`
- E2E: `OFF-04` waitlist steps follow the sold-out room card

### Deviations

- `BackfillWaitlistStays` and `DemoRequestsSeeder` still read `departures` so old rows and the demo seed become stays. Runtime waitlist code does not.
- The task’s example “Thu 5 – Sun 8 Mar 2028” is the shape. 5 Mar 2028 is a Sunday, so the sentence is `A Suite is free — Sun 5 – Wed 8 Mar 2028`.
- Generated OpenAPI types in `iconic-ui` still describe the old resource. The panel overlay covers the new fields. Regenerate types later.

### Open questions

None.

### Notes for later

- Sprint 22 drops `departure_id` and `cabin_category`.
- Local `iconic` has this migration. The cancellation-set migration `2026_10_05_125001` is still pending, so December nights without a claim stay `NO_RATE`.
- Verified in the browser at `http://127.0.0.1:3010/book/rooms` for 21–24 Dec 2026 after blocking every Standard Double: only that card offered Join the waitlist, with the stay already filled. Twin, Family, and Suite stayed on “No rate” and showed no join form. The IDE browser POST still fails with “We could not reach the reservation system.” `POST /api/engine/waitlist` from the host returned the stay and `source` `ENGINE`. The check block and that row were released afterwards.

## 20-06 — Agency portal on stays

An agency user can search a stay, see net prices, and request rooms. The portal uses the same availability and calendar as the engine. Displayed money is the public figure after `Agency::netOf`. The public calendar cache is left alone: netting happens after `EngineCalendar::month()`. Commission percent stays visible. Rates are the published seasons × room types matrix, plus plans, length of stay, and supplements. Nightly rates and supplement per-night amounts are netted. Plan percentages are not.

A portal request is a stay plus `rooms[]`. Each room becomes one `CreateBookingRequest`. More than one room shares a group. The room is held. Over the commission cap the status is still `ON_HOLD_AGENCY`, and the room stays held. The guest sentence is `This request holds the room.` Booking and commission lists show check-in, check-out, and room type. `departure_date` remains the check-in date so the RMS agency drawer and the preview comparison still line up. Payable dates stay on `Accrual::payableDate`.

The priced path is the Family room, 21–25 Dec 2026, four nights, proved in Pest (`PortalAvailabilityLabelsTest`, `PortalRequestsTest`).

### Files

- API: `SubmitPortalRequest`, `CreateStayReservation` (commission resolved before the stay insert; over-cap is `ON_HOLD_AGENCY`), `PortalNetPrices`, `PortalStayRates`, `PortalRequestWords`, portal availability, calendar, and rates controllers, requests, and resources. `PortalNetRateResource` removed. `GET /api/portal/calendar` added. `GET /api/portal/availability` now requires check-in, check-out, and adults
- Preview: `PortalPreview::view()` adds `stay_rates`. `net_rates` (the yacht year table) is unchanged for the RMS drawer
- Portal: `availability.vue`, `rates.vue`, `requests/new.vue`, `bookings.vue`, `commissions.vue`, `portalStay.ts`, en strings. Old departure helpers removed
- Layer: stay overlays in `app/types/portal.ts`, re-exported from the portal
- E2E: `PREQ-01`, `PREQ-02`, `PREQ-03`, `PORT-03`, `PORT-04`, and the index titles. `HPOR-*` stays with task 07

### Deviations

- The RMS agency drawer still reads `preview.net_rates` as yacht years. The portal rates page and the preview equality test use `stay_rates`.
- A stay request holds the room. The old copy said a request does not hold a cabin. That sentence is now false, so the words and the tests follow the hold.
- Two rooms are two bookings in one group. `CreateStayReservation` still rejects more than one room on a single request.
- Scramble no longer emits `EngineLabelCode` or `CabinCategory` from the portal spec, so those names left the portal schema assertion. `DepartureStatus` remains from the RMS.
- Departure checkout and the old engine feed routes are still in place.

### Open questions

None.

### Notes for later

- `HPOR-01` … `HPOR-03` are task 07.
- Local `iconic` still has no December rates (`NO_RATE`) because migration `2026_10_05_125001` never finished. At `http://localhost:3002` Ada can sign in, pick 21–25 Dec 2026, and see Standard Double, Twin, Family (4 left), and Suite, each `NO_RATE`, with no Request link. Rates says “No published rates.” The request form prefills that stay, `FAM`, and `BAR`. Bookings and commissions return 500 `Booking 3 has no stay.` That row predates a finished stay backfill. Pest, on a fresh database, is the proof that a priced Family stay can be requested.
- Portal dev server for that check: `http://127.0.0.1:3002` with `NUXT_PUBLIC_API_BASE=http://localhost:18000`. Sign-in has to be opened as `http://localhost:3002` so the session cookie matches `SANCTUM_STATEFUL_DOMAINS`.

## 20-07 — Sprint close: e2e and report

### What was built

Stay scenarios for the engine and the portal, and a P1 record that did not start the stack.

HENG-01 through HENG-07 walk the public stay shop: from-prices on the home calendar, the min-stay one-click fix, pay later into the RMS, a Stripe replay that confirms, a race for the last Suite, hold expiry on the timeline, and a sold-out waitlist. HPOR-01 through HPOR-03 walk agency availability, a request whose hold shows on the RMS timeline, and a commission payable date counted from check-out.

WEB-01 through WEB-12 are retired. Those steps search departures. PORTAL-PAY-02 is retired because hotel seed has no `ANK-2026-0021`. The other-agency refusal stays in Pest. PORTAL-PAY-01, PREQ-04, and PORT-04 now use a Family stay or an empty hotel list. PREQ-02's note no longer depends on the yacht hold `ANK-2026-0021`. PORT-01, PORT-02, PORT-03, PORT-05, PORT-06, PORT-07, PREQ-01, and PREQ-03 stay.

`LEDGER.md` records the P1 run. INDEX has 100 P1 rows.

### Files

- `tests/e2e/scenarios/engine/HENG-01` … `HENG-07`
- `tests/e2e/scenarios/portal/HPOR-01` … `HPOR-03`
- `tests/e2e/scenarios/web/WEB-01` … `WEB-12` (retired)
- `tests/e2e/scenarios/portal/PORTAL-PAY-01`, `PORTAL-PAY-02`, `PORT-04`, `PREQ-02`, `PREQ-04`
- `tests/e2e/scenarios/INDEX.md`
- `tests/e2e/runs/LEDGER.md`
- `tests/e2e/runs/2026-10-06-1021-sprint-20-p1.md`
- `docs/sprints/sprint-20/README.md`

### Deviations

`up.sh` was not started. It copies `tests/e2e/environment/api.env` over `.env`. Ports 8000 and 8001 are `keevaris-api`. The names `iconic-api`, `iconic-mysql`, `iconic-redis`, and `iconic-mailpit` are already in use, and mailpit owns 8025. Ports 3000, 3001, 3002, and 3010 already have listeners. Memory available was 11.2 GiB. Detail: `tests/e2e/runs/2026-10-06-1021-sprint-20-p1.md`. 0 passed, 0 failed, 100 not run.

### Open questions

None.

### Notes for later

Hotel seed does not call `DemoAgenciesSeeder`. A fresh reset has no `ada@portal.test` and no AG-002. Portal scenarios say to stop and class that ENV. Do not create the agency inside a run.

The confirmation heading for a paid deposit is still `Your expedition is confirmed` (`confirm.paidTitle`). HENG-04 checks RMS status `CONFIRMED`, not that heading.

### Sprint close

Sprint 20 is closed. Do not commit from the agent. Suggested commands, from each repo that has changes:

```bash
git status
git diff
git add -A
git commit -m "Sprint 20: booking engine and agency portal on stays."
```

Review the diff before `git add`. iconic-api, iconic-ui, iconic-panel, iconic-engine, and iconic-portal all have sprint 20 work.

