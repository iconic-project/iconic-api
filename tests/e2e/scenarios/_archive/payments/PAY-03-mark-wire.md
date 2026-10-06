# PAY-03 · Awaiting wire, then mark received
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina then cfo@iconic.test
- **Start:** reset

## Why
A wire is pledged, not paid, until finance marks it received. Carolina can see the window; only the finance flag settles it.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0014 (R. Ellison). Overview, then **Payments**.
2. Sign out. Sign in as `cfo@iconic.test` / `password`. Open `/rms/commercial/payments` (or reopen 0014 → Payments).
3. On `ANK-2026-0014-D01` click `Mark received`. Modal title `Wire received — bank reference`. Bank reference `SWIFT-PAY03`. Submit.

## Expected
- [ ] E1 · Carolina Overview: status `PENDING PAYMENT`. Paid `USD 0`. A pledged line `USD 2,660 awaiting wire · window ends …`. Balance due `USD 26,600`. Payments row `ANK-2026-0014-D01` · Deposit · Wire transfer · `USD 2,660` · status shows `Mark received` (AWAITING WIRE).
- [ ] E2 · Payments & Revenue ledger (Carolina or CFO): 0014 is the newest row, same reference, `Mark received` still visible before step 3.
- [ ] E3 · After CFO submits: toast `Wire marked received`. 0014 header `CONFIRMED`. Paid `USD 2,660`. Balance `USD 23,940`. Ledger status `SETTLED`. `Mark received` is gone.
- [ ] E4 · History (open 0014 as CFO or Carolina): `Wire received · ANK-2026-0014-D01 · SWIFT-PAY03` and a System status change `Deposit settled · ANK-2026-0014-D01` (PENDING PAYMENT → CONFIRMED).

## Notes
CFO has `payments.mark_wire_received` and no New Reservation. One user per browser context — sign out before the CFO steps. Amounts: `fixtures/reference-values.md`.
