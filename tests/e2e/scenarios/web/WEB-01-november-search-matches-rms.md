# WEB-01 · November search matches the RMS
- **Tags:** sprint-8, web
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
The public engine must render only on-sale departures. If the guest list drifts from the RMS, the shop window is lying.

## Steps
1. **Guest** (fresh context, no staff cookies). Open `http://localhost:3000/`. Confirm 2 adults, 0 children. Set the date window **Nov 2027 → Jan 2028** (two-click month picker). Click `Check availability`.
2. On `/itineraries` read the three cards: **Western Realm**, **Northern Passage**, **Festive Expeditions**. Expand each **Departures (N)**. Record date, yacht, and availability label for every row.
3. **Carolina** (second context). Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/booking-engine/departures`. Date range covering Nov 2027–Jan 2028. Read each on-sale row’s date, yacht, itinerary, and **Engine shows**.

## Expected
- [ ] E1 · Guest window line includes `NOV 2027` and `JAN 2028` (or the feed’s `default_search_*` months) and `Party of 2 (2 adults)`. ⚠ UNVERIFIED — i18n `itineraries.window` + task 08 browser.
- [ ] E2 · Three cards. Suites from **USD 13,300**. WEST **Departures (6)**, NORTH **(6)**, FEST **(3)**. DEP-013 (19 Dec 2027 ANAMARA festive charter) is absent. Festive rows append `+ festive`. ⚠ UNVERIFIED — task 08 browser; `fixtures/reference-values.md`.
- [ ] E3 · Every guest row’s date (`j M Y`), yacht (ANAMARA / ANATIVA), and label (`AVAILABLE` unless the RMS **Engine shows** says otherwise) match Carolina’s Departures list. Labels are the API strings (`AVAILABLE`, `ONLY N CABIN(S) LEFT`, `LIMITED AVAILABILITY`, `FULL · WAITLIST`, `CLOSED — ENQUIRE`, `PRIVATE CHARTER ONLY`).
- [ ] E4 · OPENING-27 badge / dealbar on Nov–Dec WEST and NORTH if the feed lists it. If the badge is missing, classify **BUG** — do not drop the expectation. ⚠ UNVERIFIED — task 08/09 once saw `offers: []`.

## Notes
Guest context has no panel session. Do not type a promo code on this page. Slugs after Select: `/itineraries/western-realm`, `/itineraries/northern-passage`, `/itineraries/festive-expeditions`. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
