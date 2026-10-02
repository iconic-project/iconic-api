# WEB-02 · Drafts, hidden departures, paused offers and promo codes never leak
- **Tags:** sprint-8, web
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Doc 04 rule 5: drafts never leak. A paused offer or a promo code on the public feed is a product bug.

## Steps
1. **Carolina.** Sign in. Open `http://localhost:3001/rms/booking-engine/itineraries`. `＋ New itinerary`. Code `E2E8`, Name `E2E Draft Isles`. `Save as draft`. Do **not** publish.
2. Open `/rms/booking-engine/departures`. ANATIVA chip. On DEP-002 (`7 Nov 2027`) set Status to `Hidden`.
3. Open `/rms/booking-engine/offers`. Open **OPENING-27**. `Pause`. Toast `Promotion paused — removed from the booking engine in < 30 seconds.`
4. **Guest** (fresh context). Open `http://localhost:3000/`. 2 adults, Nov 2027–Jan 2028. `Check availability`. Read cards, expanded rows, and page source / visible text.
5. In DevTools, open `GET http://localhost:8000/api/engine/feed` (or the engine’s proxied feed). Search the JSON for `E2E Draft Isles`, `E2E8`, `2027-11-07` on ANATIVA / DEP-002 identifiers, `OPENING-27`, `ICONIC10`, `ADVISOR5`, `EARLY500`, `VIRTUOSO-EARLY`.

## Expected
- [ ] E1 · Carolina: E2E Draft Isles is `DRAFT`. DEP-002 **Engine shows** `NOT SHOWN`. OPENING-27 status `PAUSED`.
- [ ] E2 · Guest itineraries: no card named `E2E Draft Isles`. No 7 Nov 2027 ANATIVA row. No OPENING OFFER badge. ⚠ UNVERIFIED — wording from i18n / INV-07.
- [ ] E3 · Feed JSON contains none of: `E2E Draft Isles`, `E2E8`, `OPENING-27`, `ICONIC10`, `ADVISOR5`, `EARLY500`, `VIRTUOSO-EARLY`. Hidden departure identifiers are absent (no ANATIVA 7 Nov row). Promo codes never appear as keys or string values.
- [ ] E4 · Guest pages (home, itineraries, any trip details opened) do not display those strings.

## Notes
Wait up to 30 s (or hide the tab and show it) for the 15 s feed revalidate after pause / hide. Do not publish the draft. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
