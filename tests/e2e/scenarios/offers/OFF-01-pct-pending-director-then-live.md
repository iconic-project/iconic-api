# OFF-01 · A PCT offer is PENDING DIRECTOR until approved, then LIVE
- **Tags:** sprint-8, offers
- **Priority:** P1
- **Users:** Carolina (Admin — `offers.manage` and `offers.approve`)
- **Start:** reset
- **Needs:** two browser contexts (Carolina + Guest)

## Why
Price-affecting offers must not reach the engine without Director approval (K2). There is no separate Director demo user — Carolina’s Admin role holds `offers.approve`.

## Steps
1. **Carolina.** Sign in. Open `http://localhost:3001/rms/booking-engine/offers`. Date range **All dates**. `＋ New offer`.
2. Code `E2EPCT8`, Internal name `E2E percent`, Benefit type **Percent off cabin rate**, Value `8`. Channel **D2C — public engine**. Cabin types **Suites**. Itineraries **Western Realm** only (Festive is disabled). Badge `E2E 8`, tick badge on card and on departure rows. Price line `E2E percent −8%`. `Save` (not Save as draft).
3. Read the row status and toast. Open the drawer → **Approve**. Reason `E2E approve E2EPCT8`. Submit.
4. **Guest** (fresh context). Within 30 s, `http://localhost:3000/`, 2 adults, Nov 2027–Jan 2028. Expand Western Realm. Look for the `E2E 8` badge / dealbar. Confirm `GET /api/engine/feed` lists `E2EPCT8` (or the public offer fields — code may be omitted from the feed; then match badge `E2E 8`).

## Expected
- [ ] E1 · After save: status **PENDING DIRECTOR**. Toast `Submitted — a Director must approve before this offer goes live.` Guest feed/pages do **not** show `E2E 8` yet. ⚠ UNVERIFIED — i18n `offers.pendingToast`.
- [ ] E2 · After approve: status **LIVE**. Toast `Offer approved — LIVE. The booking engine picks it up in < 30 seconds.` Reason was required.
- [ ] E3 · Within 30 s the engine shows the new badge on a matching WEST November row (or the card dealbar). Feed top-level offers include the live public offer. ⚠ UNVERIFIED — EngineOfferResource fields (no `id` / `reference` / `status`).

## Notes
Do not tick Festive Expeditions. Do not use a seeded code. Guest context has no staff cookies. If Approve is missing, classify **BUG** (Admin has `offers.approve`). Before any other click on the engine, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
