# INV-07 · Status and engine label
- **Tags:** sprint-3, inventory
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

Status after sprint 17 task 07: a new block is a stay (`starts_on` / `ends_on`) on rooms or a room type plus a count. The step that ticks a yacht and a departure is the old form. Task 08 rewrites it.

## Why
The engine label is computed in the API. Changing status, or dropping free cabins to the urgency threshold, must update the row and the KPIs.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/booking-engine/departures`. Click the **ANATIVA** chip (8 rows).
2. On DEP-002 (`7 Nov 2027`), change **Status** from `On sale` to `Closed to sale`.
3. Change the same row to `Hidden`.
4. Open Internal Blocks (`/rms/operations/blocks`). `＋ New block`. Yacht ANATIVA, departure `14 Nov 2027 · Western Realm` (DEP-004, 9 free), cabins Suite 01, Suite 02, Suite 03, Suite 04, Suite 05, Suite 06, reason Fam trip. `Create block`.
5. Return to Departures, ANATIVA chip. Read DEP-004’s **Engine shows** cell.

## Expected
- [ ] E1 · After Closed: DEP-002 label `CLOSED — ENQUIRE`. KPIs (ANATIVA filter): on sale **7** of 8 · bookable **63**. Festive pills on 19 / 26 Dec still `FESTIVE +USD 750 PP` (fixture, not a dirty local 800).
- [ ] E2 · After Hidden: DEP-002 label `NOT SHOWN`. Live KPIs exclude it: on sale **7** of 8 in view · bookable **63**.
- [ ] E3 · After the six-cabin block, DEP-004 label `ONLY 3 CABINS LEFT` (free 3, threshold 3). Inventory shows `6 BLOCKED` and `3 FREE`.

## Notes
Labels: `App\Support\Inventory\EngineLabel`. Festive amount: `fixtures/reference-values.md` (`rates.festive_supplement_pp` = 750). Hidden rows are excluded from live KPIs (`NOT_SHOWN`).
