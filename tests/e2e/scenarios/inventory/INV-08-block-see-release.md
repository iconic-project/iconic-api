# INV-08 · Block, see, release
- **Tags:** sprint-3, inventory
- **Priority:** P1
- **Users:** Mateo
- **Start:** reset

## Why
An internal block is the first real claim holder. Calendar and layout must show the cabins as blocked, and release must free them and move the row.

## Steps
1. Sign in as `mateo@iconic.test` / `password`. Header `MATEO R. — MANAGER`. Open `http://localhost:3001/rms/operations/blocks`. Active list shows `BLK-001` (ANAMARA · Suite 07–08 · 14 Nov 2027, Created by `System`).
2. Click `＋ New block`. Yacht **ANATIVA**. Tick departures `7 Nov 2027 · Northern Passage` and `14 Nov 2027 · Western Realm` (each shows `9 free`). Tick Suite 01, Suite 02, Suite 03. Reason **Fam trip**. `Create block`.
3. Open `/rms/reservations/calendar`. Set Date range to **Year 2027**. Read ANATIVA Suite 01–03 on 7 Nov and 14 Nov.
4. Open `/rms/reservations/yacht-layout`, Year 2027, pick `7 Nov 2027`, then `14 Nov 2027`.
5. Return to `/rms/operations/blocks`. On the BLK-002 row click `Release`. Confirm `Release BLK-002` (note optional). Switch the status chip to **Released**. Open the BLK-002 drawer → **History**.

## Expected
- [ ] E1 · Toast `BLK-002 created — 6 cabins blocked`. Active table has two rows; BLK-002 scope lists both ANATIVA dates and Suite 01–03.
- [ ] E2 · Calendar Year 2027: ANATIVA Suite 01, 02, 03 on 7 Nov and 14 Nov show `FAM`. ANAMARA Suite 07–08 on 14 Nov still `FAM` (BLK-001).
- [ ] E3 · Layout on both dates: those three ANATIVA cabins `Blocked · Fam trip`. After release they read `Available`.
- [ ] E4 · Toast `BLK-002 released`. Under **Released**, BLK-002 shows a mono `Released {date} by Mateo R.` line. History sentences include `Created` then `Released`.

## Notes
Mateo has `blocks.manage` (`fixtures/accounts.md`). Demo block stays ANAMARA / BLK-001.
Yacht / departure / cabin ticks are `label.chkline` wrapping the checkbox. Tick ANATIVA, both listed departures, and Suite 01–03 **before** Create. INV-10 release of BLK-001 is a later scenario; this one must finish Create + Release of BLK-002.
