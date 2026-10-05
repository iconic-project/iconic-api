# HINV-03 · Block conflict names the night
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P1
- **Users:** Mateo
- **Start:** reset

## Why
A second block on a taken room-night must stay in the modal and name that night and the holder. The sentence is `ConflictMessage`.

## Steps
1. Hotel seed. Sign in as Mateo. Open `http://localhost:3001/rms/operations/blocks`.
2. **＋ New block**. Property **Hotel Demo**. Set the stay to one night: check-in `2026-10-09`, check-out `2026-10-10`. Tick **Room 101**. Reason **Maintenance**. Notes `HINV-03`. **Create block**.
3. **＋ New block** again. Same property, same stay, **Room 101**, same reason. **Create block**.
4. If the calendar shows a **Sold** bar on a room, block that room across that night instead of step 3 and read the sentence.

## Expected
- [ ] E1 · Step 3 leaves the modal open. The error text is `Room 101 is blocked on Fri 9 Oct 2026 (BLK-001)`. No second toast `Block created`. The active list has one row.
- [ ] E2 · A sold night uses the same shape with `sold` and the booking reference, for example `Room 101 is sold on Fri 9 Oct 2026 (ANK-2026-0001)`. Hotel seed has no booking (`HotelSeeder` stops before bookings). **New reservation** still asks for a departure. Do not mark E2 passed from the block sentence in E1.

## Cross-checks
- `bin/db-check.sh 'App\Models\InternalBlock::query()->count()'` → `1`

## Notes
`Fri 9 Oct 2026` is `D j M Y` from `ConflictMessage`. Do not put the word `create` in the tinker expression. `db-check.sh` refuses it. If check-in `2026-10-09` is before the stay control’s minimum date, pick the first night the control allows, block that one night twice, and expect the date in the sentence to be that night. The reference in parentheses is the first block.
