# WEB-09 · Pay deposit → replay expired → request remains

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
K6: an unfinished payment must not lose the booking. The online advantage comes off; the request stays for the team.

## Steps
1. **Guest.** Same as WEB-08 through `Pay deposit & confirm` (email `e2e.web09@iconic.test`). Do **not** enter card data.
2. From `iconic-api`:
   ```
   tests/e2e/bin/replay-stripe-checkout.sh --expired ANK-R-2026-0043
   ```
3. Guest: open `/book/confirmation` if the UI is still polling.
4. **Carolina.** Open Booking Requests, then the `ANK-R-2026-0043` panel → **History**.

## Expected
- [ ] E1 · Booking stays `REQUESTED` with `ANK-R-2026-0043`. No `ANK-` drawn. Cabin hold remains (request hold, not released).
- [ ] E2 · `online_deposit` is false. Price lines no longer include `online_deposit`. A promo line, if present, is recomputed on the unreduced figure. ⚠ UNVERIFIED — task 04 fallback.
- [ ] E3 · History: `Online deposit not completed — the online advantage was removed; the request stays open for the team`.
- [ ] E4 · Confirmation may show `Payment not completed` / `Your request is safe`. ⚠ UNVERIFIED — i18n `confirm.expired*`.
- [ ] E5 · Mailpit has no invoice / summary / receipt for this guest (no settlement).

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first(["status","online_deposit","reference"])'` → status `REQUESTED`, `online_deposit` false, `reference` null.

## Notes
Never live-mode Stripe. `--expired` is the engine Checkout Session path, not a payment-link replay. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
