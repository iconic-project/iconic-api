# PIPE-01 · Pipeline KPIs equal Payments & Revenue; bound deals stay locked
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Pipeline cash is the payments ledger. A deal whose stage follows a booking cannot be dragged.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/pipeline`.
2. Read Collected, Scheduled in, Awaiting first payment, Open pipeline, Weighted forecast and Overdue.
3. Open `http://localhost:3001/rms/commercial/payments`. Read the same cash figures.
4. Return to the pipeline. Read **Stage map**.
5. A fresh seed has no deal cards (nothing backfills them). Submit one engine pay-later request on a free November cabin (WEB-07 shape) so a deal appears, or use the deal PIPE-03 just created if this run is not alone.
6. Open that deal. Try to move it. Read the card.

## Expected
- [ ] E1 · Notice: `Cash is computed from the RMS payments ledger. Stages 1–4 are moved by sales. Stages 5–7 follow the booking status and stay locked. LOST needs a reason.`
- [ ] E2 · **Collected** equals Payments & Revenue **Collected to date**. **Awaiting first payment** equals **Pending payments**. **Overdue** equals **Overdue**. **Scheduled in** is the pipeline’s own total of confirmed and fully paid balances; the payments page has no card with that name, so do not compare it to **Deposits**. Open pipeline is **USD 0** before any open deal exists. ⚠ UNVERIFIED — task 08 on a non-reset database saw collected USD 103,493, scheduled in USD 380,347, awaiting first payment USD 433,547, open pipeline USD 0. Do not copy those figures onto a reset.
- [ ] E3 · Stage map lists New lead, Qualifying, Quoted, Negotiation, Deposit pending, Booking confirmed, Won — completed, Lost, with who owns each stage.
- [ ] E4 · The deal for the new request is **Deposit pending** and shows **LOCK**. Move to… does not offer a stored stage. Dragging it does not change the stage.

## Notes
Do not type a KPI. If the two screens disagree, that is a **BUG**.
