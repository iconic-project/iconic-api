# PAY-02 · Record the balance → FULLY PAID
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A settled balance must move CONFIRMED → FULLY_PAID through System, with the payment reference as the reason. Typing the status by hand is the other path.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0003. **Payments** tab.
2. Under **Record a payment (finance)** set Type `Balance`, Method `Card (Stripe)`, Amount `23940`. Date received may stay today. `Record payment`.
3. Read the header, Overview Paid / Balance, and the Payments table.
4. Open **History**.

## Expected
- [ ] E1 · Toast `Payment recorded`. Header becomes `ANK-2026-0003 · FULLY PAID`.
- [ ] E2 · Overview: Paid `USD 26,600`. Balance due `USD 0`. Deposit tick still `Deposit 10% · USD 2,660 ✓`. List Balance for 0003 is `USD 0`.
- [ ] E3 · Ledger has a second row `ANK-2026-0003-B01` · Type `Balance` · Method `Card (Stripe)` · Amount `USD 23,940` · `SETTLED`.
- [ ] E4 · History includes `Payment recorded · ANK-2026-0003-B01 · USD 23,940 · SETTLED` under Carolina M., and a System line `Status CONFIRMED → FULLY PAID` with `Reason: Balance settled · ANK-2026-0003-B01`.

## Notes
23940 = 26600 − 2660. Do not use `→ FULLY PAID` on Overview — that is the manual path and needs a typed reason.
