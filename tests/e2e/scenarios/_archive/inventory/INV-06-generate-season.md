# INV-06 · Generate a season
- **Tags:** sprint-3, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Generate season must create the ALT pair for each Sunday and skip existing (yacht, date) rows. The newest reference after a reset + this range is DEP-042.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/booking-engine/departures`. Click `Generate season…`.
2. Defaults after a fresh seed are **From** `2028-01-02`, **To** `2028-03-26`, both yachts checked, pattern **Alternate Western Realm / Northern Passage**, **Create as** Closed to sale. **Uncheck** `Use Festive Expeditions for departures from 15 Dec to 2 Jan` (2 Jan is inside that window; leaving it on would make both yachts FEST that week).
3. Click `Generate`.
4. On the list, open `2 Jan 2028` ANAMARA and `2 Jan 2028` ANATIVA, then `9 Jan 2028` for both.
5. `Generate season…` again with the same range, festive still off, both yachts, ALT. Click `Generate`.
6. Run `tests/e2e/bin/db-check.sh 'App\Models\Departure::query()->latest("id")->value("reference")'`.

## Expected
- [ ] E1 · First toast: `26 departures created as Closed to sale — open them when ready. 0 skipped.` Count `42 departures · all dates`.
- [ ] E2 · `2 Jan 2028` · ANAMARA · Western Realm; `2 Jan 2028` · ANATIVA · Northern Passage. `9 Jan 2028` swaps: ANAMARA Northern Passage, ANATIVA Western Realm.
- [ ] E3 · Second toast: `0 departures created as Closed to sale — open them when ready. 26 skipped.` Count still `42 departures · all dates`.
- [ ] E4 · `db-check` prints `"DEP-042"`.

## Cross-checks
- `bin/db-check.sh 'App\Models\Departure::query()->latest("id")->value("reference")'` → `"DEP-042"`

## Notes
ALT table: Sprint 3 REPORT task 02 (festive window off). `DEP-042` = DEP-016 + 26. This scenario starts from `reset`, so the number holds even if earlier scenarios created departures.
Leaving the festive checkbox ticked is a **step miss**, not a product bug: the first generate then creates 13 (not 26) and the latest reference is not `DEP-042`. Uncheck it before Generate.
