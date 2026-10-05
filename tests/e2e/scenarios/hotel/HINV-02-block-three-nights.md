# HINV-02 · Block a room for three nights
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P1
- **Users:** Mateo
- **Start:** reset

## Why
A drag on free cells of one room must open New block for that stay. The bar and the free count must cover exactly those nights.

## Steps
1. Hotel seed, as in HINV-01. Sign in as `mateo@iconic.test` / `password`. Header shows Mateo as Manager. Open `http://localhost:3001/rms/reservations/calendar`. Property **Hotel Demo**. Click **Today**. **14 nights**.
2. Read **Standard Double free** on the first three night columns. Each is `10`.
3. On **Room 101**, press the first night cell and drag across the next two night cells (three nights). Release.
4. The **New block** modal is open. The stay is those three nights (check-out is the day after the third night). Room 101 is ticked. Do not tick **Choose a room type and a count**. Reason **Maintenance**. Notes `HINV-02`. Click **Create block**.

## Expected
- [ ] E1 · Toast `Block created`. The modal closes.
- [ ] E2 · Room 101 shows one blocked bar on exactly those three nights. The bar text is the new reference (`BLK-001` on a fresh hotel seed). The other rooms have no bar.
- [ ] E3 · **Standard Double free** is `9` on those three nights and `10` on every other night in the window. Twin, Family, and Suite free counts are unchanged (`6`, `5`, `3`).

## Notes
Mateo has `blocks.manage`. Dragging onto a claimed cell must not extend the range. This seed has no claims, so the three cells are free. Stay length comes from the published rules (minimum 1, maximum 30). Do not type `1` or `30` if the stay control is missing; stop and record that the rules document has no `stay` group.
