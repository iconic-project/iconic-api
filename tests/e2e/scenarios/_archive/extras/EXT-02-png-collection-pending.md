# EXT-02 · Switch PNG collection on; pending guest shows pending data
- **Tags:** sprint-6, extras
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Fee collection is a per-booking choice (I10). Pending PNG (missing DOB or nationality) contributes 0 to the fee but must stay visible as “pending data”. The deposit must not move.

## Steps
1. Sign in as Carolina. Open ANK-2026-0014. **Extras** tab. Read the PNG checkbox sentence (unchecked).
2. Tick `Guest pays the PNG park entry fee to Iconic …`. Wait for toast. The panel lands on Overview — read Galápagos fees, Charges total, Balance, Deposit, extras-due line.

## Expected
- [ ] E1 · Unchecked sentence includes `USD 200` (Robert’s stored foreign-adult fee), `Unchecked = paid directly at SCY airport on arrival`, and `1 guests pending data` (empty Guest 2). ⚠ UNVERIFIED — task 08 browser (`feeLabel` wording).
- [ ] E2 · Toast `Fee collection updated`. Overview: **Galápagos fees collected** with suffix `pending data` `USD 200`. Charges total `USD 26,800`. Balance due moves by 200. Deposit still `USD 2,660`. Line `Extras and fees due by 11 Nov 2027, 00:00` (72 h before departure). ⚠ UNVERIFIED — task 08 browser.

## Notes
0014 has one named guest with PNG stored and one empty slot (`PENDING`). TCT stays unchecked. `payments.extras_due_hours` is 72.
