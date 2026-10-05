# HINV-04 · Shorten and release a block
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P2
- **Users:** Mateo
- **Start:** reset

## Why
Shorten moves one edge and needs a reason. Release needs a note. Both edges, or an empty note, must not submit.

## Steps
1. Hotel seed. Sign in as Mateo. Open `http://localhost:3001/rms/operations/blocks`.
2. **＋ New block**. Property **Hotel Demo**. Check-in `2026-10-06`, check-out `2026-10-09` (three nights). Tick **Room 102**. Reason **Maintenance**. Notes `HINV-04`. **Create block**.
3. On that row click **Shorten**. Set check-out to `2026-10-08` and leave check-in at `2026-10-06`. Reason `HINV-04 shorten`. Click **Shorten**.
4. Open **Shorten** again. Move both check-in and check-out. Read the warning. Do not submit.
5. On the row click **Release**. Leave **Note** empty. Then type `HINV-04 release` and click **Release**. Switch the status chip to **Released**.

## Expected
- [ ] E1 · After create, the row shows 3 nights and the stay `6 Oct 2026 – 9 Oct 2026`.
- [ ] E2 · Toast `Block shortened`. The nights column is `2`. The stay ends `8 Oct 2026`.
- [ ] E3 · With both edges moved, the modal shows `Move one edge only.` and **Shorten** is disabled.
- [ ] E4 · **Release** is disabled while Note is empty. After the note, toast `{reference} released`. Under **Released**, the row names Mateo.

## Notes
Date text uses `D MMM YYYY` without a leading zero (`6 Oct 2026`). If `2026-10-06` is before the stay minimum, use the first allowed check-in and a check-out three nights later, then shorten the check-out by one day.
