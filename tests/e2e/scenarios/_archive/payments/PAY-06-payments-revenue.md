# PAY-06 · Payments & Revenue KPIs, pending, ledger, booking link
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The five KPIs and the pending / ledger / recon panels are the finance home. A wrong pending due-date (wire window vs T−120) hides the only awaiting wire.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/payments`. Date range **All dates**.
2. Read the five KPI cards, the pending table, the commissions table, the ledger and reconciliation.
3. In the pending table click `ANK-2026-0014`.

## Expected
- [ ] E1 · Date-range line `12 payments · all dates`. KPIs, in order:
  - Collected to date `USD 103,493` — `deposits + balances, all channels`
  - Of which deposits `USD 69,379` — `10% cabins · 20% charter`
  - Pending payments `USD 431,987` — `11 payments · due at T−120 per booking`
  - Overdue `USD 0` — `OPS-007 manual review — never auto-cancel`
  - Commission accrued `USD 2,328` — `payable 30 days post-cruise`
- [ ] E2 · **Pending payments — when the money arrives.** 0014 due column is `72h wire window` and status `PENDING PAYMENT`. 0003 due column is `10 Jul 2027` and status `CONFIRMED`. 0021 is `ON HOLD AGENCY`. 0018 is `CONFIRMED` with no OVERDUE pill (fixture not run).
- [ ] E3 · **Commissions — earned, payable & blocked.** Meridian Voyages / ANK-2026-0021 / 15% / `—` / `BLOCKED >12% · DIRECTOR APPROVAL (FIN-005)`. Blue Latitude Travel / ANK-2026-0007 / 10% / `USD 2,328` / `21 Dec 2027` / `ACCRUED — PAYS AT COMPLETED`. Footnote `Commission is payable 30 days after cruise completion (FIN-005). Rates above 12% are system-blocked until Commercial Director approval.`
- [ ] E4 · **Payment ledger — every payment, method & reference (§7).** Newest row is 0014 `ANK-2026-0014-D01` `USD 2,660` with `Mark received`. 0005 has both `…-B01` `USD 34,114` and `…-D01` `USD 3,791`.
- [ ] E5 · **Payment platform reconciliation (§7.3).** Notice `Reconciling the current Galápagos month (2026-09-01 – 2026-09-30). Choose a date range to change the window.` Gateway 2 · Matched 1 · Discrepancies 1 (coral). Unmatched `CH_UNMATCHED` `USD 2,660` `Unmatched gateway charge` · `Apply to booking`. `Stripe test mode`. Wire footnote mentions LEG-004.
- [ ] E6 · Clicking 0014 in pending opens the booking drawer on the **Payments** tab (Harrison is 0003; this is R. Ellison).

## Notes
KPI numbers are from a fresh `reset.sh` on 2026-09-21. Do not use the Task 08 report figures (that DB had 0014 already settled and 0018 overdue).
