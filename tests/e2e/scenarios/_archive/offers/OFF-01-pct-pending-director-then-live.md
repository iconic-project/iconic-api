# OFF-01 · A PCT offer is PENDING DIRECTOR until approved, then LIVE
- **Tags:** sprint-8, offers
- **Priority:** P1
- **Users:** Carolina (Admin — `offers.manage` and `offers.approve`)
- **Start:** reset

## Why
Price-affecting offers must not reach the engine without Director approval (K2). There is no separate Director demo user — Carolina’s Admin role holds `offers.approve`.

## Steps
1. **Carolina.** Sign in. Open `http://localhost:3001/rms/booking-engine/offers`. Date range **All dates**. `＋ New offer`.
2. Code `E2EPCT8`, Internal name `E2E percent`, Benefit type **Percent off cabin rate**, Value `8`. Channel **D2C — public engine**. Stay window: arrival `2026-12-21`, departure `2026-12-25`. Leave min nights, room types, and rate plans empty. Badge `E2E 8`, tick the card badge and the price-calendar badge. Price line `E2E percent −8%`. `Save` (not Save as draft).
3. Read the row status and toast. Open the drawer → **Approve**. Reason `E2E approve E2EPCT8`. Submit.
4. Open **＋ New reservation**. Check-in `2026-12-21`, check-out `2026-12-25`. Room type **Standard Double**. Rate plan **Best available**. Adults `2`. Read the quote lines.

## Expected
- [ ] E1 · After save: status **PENDING DIRECTOR**. Toast `Submitted — a Director must approve before this offer goes live.` Guest feed/pages do **not** show `E2E 8` yet. ⚠ UNVERIFIED — i18n `offers.pendingToast`.
- [ ] E2 · After approve: status **LIVE**. Toast `Offer approved — LIVE. The booking engine picks it up in < 30 seconds.` Reason was required.
- [ ] E3 · The reservation quote includes `E2E percent −8%`. The stay nights sit inside the stay window.

## Notes
Do not use a seeded code. A stay-window offer is checked on the staff quote. The public price calendar reads the same window.
