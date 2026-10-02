# WEB-06 · Walkthrough with ICONIC10 on both paths
- **Tags:** sprint-8, web
- **Priority:** P1
- **Users:** Guest
- **Start:** reset

## Why
K7: the server’s price is the price. A local estimate that disagrees with `POST /api/engine/quote` must not be shown as final.

## Steps
1. Guest. 2 adults, Nov 2027–Jan 2028, Western Realm, **7 Nov 2027 · ANAMARA**, Suite 03, `Next — your details`.
2. On `/book/details` open DevTools → Network. Apply promo `ICONIC10` (`Promo code` / `Apply`).
3. Leave **Option 1 · Book now, pay later** selected (or re-select it). Wait for `POST /api/engine/quote` with `online_deposit: false`. Copy every `lines[]` label/amount and `total` / `deposit` from the **response body**.
4. Compare the on-screen booking summary to that JSON. Do not type totals from memory.
5. Select **Option 2 · Instant confirmation**. Wait for `POST /api/engine/quote` with `online_deposit: true`. Copy lines and totals from the new response.
6. Compare the on-screen summary again.

## Expected
- [ ] E1 · `ICONIC10` applies (a quote line whose `code` is `ICONIC10`, or the offer `price_line` `Iconic welcome −10%`). Invalid would show `This code is not valid` — that is a **BUG** on this departure. ⚠ UNVERIFIED — promo check / i18n.
- [ ] E2 · Option 1: every price-panel line and the total equal the last quote response (`online_deposit` false). OPENING-27 may appear as a CREDIT / zero-amount line. **Do not** expect 21,067 / 20,014 (Pest LAST12 factory, not this seed).
- [ ] E3 · Option 2: panel equals the last quote with `online_deposit` true. A line for the online-deposit advantage is present (`Online deposit advantage −5%` or the published copy + live %). ⚠ UNVERIFIED — `copy.online_deposit_advantage` + rule 5.
- [ ] E4 · Switching paths re-quotes. The panel never keeps the other path’s total.

## Notes
Record the two quote bodies in the run report (no guest PII). Fixture walkthrough rows stay `⚠ UNVERIFIED` until a later reset reads the engine and the RMS booking (WEB-07 / WEB-08). Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
