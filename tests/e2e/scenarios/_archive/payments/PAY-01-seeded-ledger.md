# PAY-01 · Seeded ledger on a CONFIRMED booking
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Paid, Balance and the deposit tick must come from the ledger. If Overview still shows the cabin total as Paid, every later payment scenario is reading a lie.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Open ANK-2026-0003 (Harrison & Whitfield). Overview tab.
3. Open the **Payments** tab.

## Expected
- [ ] E1 · Header `ANK-2026-0003 · CONFIRMED`. Cabin total `USD 26,600`. Paid `USD 2,660`. Balance due `USD 23,940 · due 10 Jul 2027`. Deposit `Deposit 10% · USD 2,660 ✓`.
- [ ] E2 · Payments table has one row: Date `2 Jul 2026` · Type `Deposit` · Method `Card (Stripe)` · Reference `ANK-2026-0003-D01` · Amount `USD 2,660` · Status `SETTLED`. Gateway id `pi_3RHOM1GXX` is on the method cell.
- [ ] E3 · **Record a payment (finance)** shows Type (Deposit / Balance / Extras / Other), Method (Card (Stripe) / Stripe payment link / Wire transfer / Other), Amount (USD), Date received, Note (optional), `Record payment`.
- [ ] E4 · **Payment link** heading. Hint `Copy the link — sending it by email arrives in Sprint 7`. Buttons `Create deposit link` and `Create balance link`.

## Notes
Amounts: `fixtures/reference-values.md` (Seeded money). 0003 is the CONFIRMED Suite · 2 adults seed with a settled 10 % deposit.
