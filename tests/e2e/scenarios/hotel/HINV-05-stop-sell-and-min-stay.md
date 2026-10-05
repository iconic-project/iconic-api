# HINV-05 · Stop-sell and min-stay
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A property-wide stop-sell and a type min-stay must show on the month grid. The type row wins for that type and night. The save writes `restrictions.set` on the property.

## Steps
1. Hotel seed. Sign in as Carolina. Open `http://localhost:3001/rms/inventory/restrictions` (sidebar **Inventory** → **Restrictions**).
2. Move the month until the heading is `1 Oct 2026`.
3. Editor: **From** `2026-10-05`, **To** `2026-10-11`. Leave every weekday chip on (Mon–Sun). Leave every room type off. **Stop sell** `Yes`. Other fields **Leave unchanged**. Reason `HINV-05 stop`. **Save restrictions**.
4. Editor: **From** and **To** `2026-10-09`. Click **Standard Double** so that chip is on. **Stop sell** `Yes`. **Minimum stay** `Set`, number `3`. Reason `HINV-05 min`. **Save restrictions**.
5. Read the Standard Double row and one other type for 5 Oct through 11 Oct.

## Expected
- [ ] E1 · Twin, Family, and Suite show `SS` on each night from 5 Oct 2026 through 11 Oct 2026.
- [ ] E2 · Standard Double shows `SS` on 5, 6, 7, 8, 10, and 11 Oct 2026. On 9 Oct 2026 the cell shows `SS` and the min-stay mark `3––` (en dashes). That night’s type row is the winner, so both marks are on that save, not copied from the property-wide row.
- [ ] E3 · `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event","restrictions.set")->count()'` prints `2`.

## Notes
Empty room-type selection writes one property-wide row. A later type row does not keep the property-wide stop-sell unless this save sets **Stop sell** to `Yes` again. `3––` is minimum 3 and no maximum (`restrictionMarks`). Friday 9 Oct 2026 is the min-stay night.
