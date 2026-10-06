# EXT-01 · Add flights × 2 on a CONFIRMED booking
- **Tags:** sprint-6, extras
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A contracted extra must raise extras, charges total and balance, and must not change the deposit (share of cruise `total` only). 0003 has no seeded extras.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview**: read Cruise, Extras, Charges total, Balance due, Deposit.
2. **Extras** tab. Under **Add a service**, Service `Domestic flights GYE/UIO ↔ SCY (round-trip)`. Qty should default to `2` (`guests_summary.total`). Rate (USD) `420`. `Add to booking`.
3. After the write the panel lands on Overview — read Cruise / Extras / Charges total / Balance / Deposit. Re-open **Extras** for the row. Open **History**.

## Expected
- [ ] E1 · Before add: Cruise `USD 26,600`. Extras `USD 0`. Charges total `USD 26,600`. Balance due `USD 23,940 · due 10 Jul 2027`. Deposit `Deposit 10% of cruise charges` `USD 2,660` with tick. ⚠ UNVERIFIED — task 08 Overview labels + PAY-01 amounts.
- [ ] E2 · Toast `Extra added`. Overview: Extras `USD 840`. Charges total `USD 27,440`. Balance due `USD 24,780`. Deposit **still** `USD 2,660`. Cruise unchanged. List balance for 0003 moves to `USD 24,780`.
- [ ] E3 · Extras table: flights × 2, rate `USD 420`, amount `USD 840` (API `amount`, never qty × rate in the panel). Footer `Ancillary subtotal` `USD 840`.
- [ ] E4 · History includes `Extra added — Domestic flights GYE/UIO ↔ SCY (round-trip) × 2` (`extra.added`).

## Notes
Catalogue price from extras `initial()` FLT 420. Deposit stays a share of cruise charges (G4 / I9).
