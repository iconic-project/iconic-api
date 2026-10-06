# EXT-03 · FULLY PAID booking gains an extra
- **Tags:** sprint-6, extras
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
FULLY_PAID never regresses (I9). An extra re-opens the balance and must leave the status pill **FULLY PAID**.

## Steps
1. Sign in as Carolina. Open ANK-2026-0005. Header is `FULLY PAID`. Balance `USD 0`. **Extras** tab.
2. Add `Domestic flights GYE/UIO ↔ SCY (round-trip)`. Qty defaults to `3` (guest count). Rate `420`. `Add to booking`.
3. Read header, Overview Balance / Deposit, and the list status + balance.

## Expected
- [ ] E1 · After add: extras `USD 1,260`. Charges total `USD 39,165`. Balance due `USD 1,260` (or the on-screen charges − 37,905). Deposit still `USD 3,791`. ⚠ UNVERIFIED — 37,905 + 1,260 = 39,165; task 08 reported 39,665 after a PNG toggle on the same booking — this run starts from reset with PNG off.
- [ ] E2 · Header and list pill stay `FULLY PAID`. No OVERDUE pill.

## Notes
Task 08’s 39,665 included PNG collected 500 + FLT × 3. This scenario does **not** switch PNG on. Status must not move back to CONFIRMED.
