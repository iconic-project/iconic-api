# HINV-06 · Lucía sees, cannot edit
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
Sales Exec can read the timeline, blocks, and restrictions, and must not get the write controls. The API enforces the same permissions.

## Steps
1. Hotel seed. Sign in as `lucia@iconic.test` / `password`.
2. Open `http://localhost:3001/rms/reservations/calendar`. Click **Today**. Press a free cell on Room 101 and drag three nights.
3. Open `http://localhost:3001/rms/operations/blocks`. Open the first block row if one exists; otherwise read the empty table. Look for **＋ New block**, **Shorten**, and **Release**.
4. Open `http://localhost:3001/rms/inventory/restrictions`.
5. Open `http://localhost:3001/rms/reservations/yacht-layout`.

## Expected
- [ ] E1 · The calendar shows **Hotel Demo** and the room rows. The drag does not open **New block**.
- [ ] E2 · Blocks has no **＋ New block**, no **Shorten**, and no **Release**.
- [ ] E3 · Restrictions shows `You can view restrictions. Changing them needs the inventory permission.` There is no **Save restrictions** button. The editor fields are disabled.
- [ ] E4 · Yacht layout lands on `/rms/reservations/calendar`. The sidebar has **Inventory** → **Restrictions** and no **Yacht Layout** item.

## Notes
The view-only blocks sentence, when a drawer is opened, is `Sales Exec role: view only. Internal blocks are managed by Admin / Manager.` Hotel seed may have no block to open. E2 does not require that sentence.
