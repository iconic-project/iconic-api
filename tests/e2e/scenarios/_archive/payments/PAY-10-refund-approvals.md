# PAY-10 · Refund Approvals: approve, CFO executes, ledger negative row
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina then cfo@iconic.test
- **Start:** reset

## Why
Director approval then finance execution must write a negative REFUNDED row. The queue is empty after reset, so this scenario creates the request.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/operations/refunds`. Date range **All dates**. Confirm the empty queue.
2. Open `/rms/reservations/bookings`. Open ANK-2026-0003. `→ CANCELLED`. Reason `E2E refund 0003`. `Record`.
3. Open `/rms/operations/refunds`. On ANK-2026-0003 click `Approve`. Modal `Approve refund`. Reason `E2E approve 0003`. Submit.
4. Sign out. Sign in as `cfo@iconic.test` / `password`. Open `/rms/operations/refunds`. On the approved row click `Execute`. Modal `Record refund execution`. Amount is locked. Method `Card (Stripe)`. External reference optional. `Record execution`.
5. Open ANK-2026-0003 **Payments**.

## Expected
- [ ] E1 · After reset: `0 refunds · all dates`. Notice includes `Cancellation percentages are system logic only (LEG-001)` and `SLA 15 business days.` Empty copy `No refund requests in this date range.` Filters All / Pending / Approved / Executed / Rejected.
- [ ] E2 · After cancel: 0003 row · policy band `≥120 days → 5%` · Penalty `USD 1,330` · Refund due `USD 1,330 of USD 2,660 paid`. Date-range line is no longer `0 refunds`.
- [ ] E3 · After Carolina approves: toast `Refund approved`. Filter **Approved** shows the row. `Execute` is visible to CFO (and to Admin; this scenario uses CFO).
- [ ] E4 · After CFO executes: toast `Refund recorded`. Filter **Executed** shows `EXECUTED`. 0003 Payments tab has `ANK-2026-0003-R01` · Type `Refund` · Amount `−USD 1,330` · Status `REFUNDED`. Overview Paid is `USD 1,330` (2,660 − 1,330).

## Notes
One user per context — sign out before CFO. Execute amount is readonly `refund_due`. The refund itself is made in the payment platform; this only records the negative ledger row. Band from configured `cancellation.bands` (≥120 d / 5 %).
