# INV-01 · Seeded inventory in the Calendar
- **Tags:** sprint-3, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The calendar and yacht layout must show the seeded 2027 inventory and the demo fam-trip block. If the default date range hides the seed, the rest of inventory e2e looks empty.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/calendar`.
2. The default range is today → +6 months and is empty of demo Sundays. In **Date range**, choose **Year 2027** (`2027-01-01`–`2027-12-31`). If that chip is missing (machine date ≥ 2028), set Custom to those dates.
3. Read the date columns, both yacht sections, the ANAMARA Suite 03 / 05 / 07 / 08 cells on 14 Nov, and all ANAMARA cabins on 19 Dec.
4. Open `http://localhost:3001/rms/reservations/yacht-layout`. Apply the same Year 2027 range. In **Departure**, pick `14 Nov 2027`, then `19 Dec 2027`.

## Expected
- [ ] E1 · Eight date columns: `7 Nov 2027`, `14 Nov 2027`, `21 Nov 2027`, `28 Nov 2027`, `5 Dec 2027`, `12 Dec 2027`, `19 Dec 2027`, `26 Dec 2027`.
- [ ] E2 · Columns `19 Dec 2027` and `26 Dec 2027` show `FESTIVE`. The other six do not.
- [ ] E3 · Two yacht headers (`⛵ ANAMARA`, `⛵ ANATIVA`), each with nine cabin rows: Owner's Suite is **not** first on the calendar (Suite 01–08 then Owner's Suite, seed sort).
- [ ] E4 · ANAMARA Suite 07 and Suite 08 on 14 Nov show `FAM`. Tooltip on Suite 07: `Suite 07 · 14 Nov 2027 · ANAMARA — Blocked: Fam trip (BLK-001)`.
- [ ] E5 · ANAMARA Suite 03 on 14 Nov shows `0007`. Suite 05 on 14 Nov shows `PEND`. ⚠ UNVERIFIED — task 10 calendar state table / REPORT browser.
- [ ] E6 · ANAMARA every cabin on 19 Dec shows `CHARTER`. ⚠ UNVERIFIED — task 10 REPORT (seed charter ANK-2026-0012).
- [ ] E7 · Yacht Layout for 14 Nov shows both decks side by side. Owner's Suite is first on each deck, then Suite 01–08. ANAMARA Suite 07 and Suite 08 read `Blocked · Fam trip`. ANATIVA cabins read `Available`.
- [ ] E8 · Yacht Layout for 19 Dec: ANAMARA cabins `Charter · 0 AD` (Carolina owns the charter — no 🔒). ANATIVA still `Available`. ⚠ UNVERIFIED — task 10 REPORT (`party_label` 0 AD).

## Notes
Values: `fixtures/reference-values.md` (Seeded inventory and bookings). Demo block is ANAMARA, not the prototype’s ANATIVA row. Do not treat ANAMARA 2027 cells as free.
