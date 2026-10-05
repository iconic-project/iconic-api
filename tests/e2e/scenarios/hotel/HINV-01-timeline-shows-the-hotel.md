# HINV-01 · Timeline shows the hotel
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The calendar is rooms by night. A hotel seed with no claims must show every room under its type, a week of nights, and an occupancy row, without the page body scrolling sideways.

## Steps
1. The API seed is hotel by default (`ICONIC_SEED_MODE=hotel`). Do not accept ANAMARA as this scenario.
2. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/calendar`.
3. The property control shows **Hotel Demo**. Click **Today**. Leave **14 nights** selected.
4. Read the occupancy row, the four free rows, and the room groups.

## Expected
- [ ] E1 · Property is **Hotel Demo**. Chips **14 nights** and **31 nights** are present. **14 nights** is on.
- [ ] E2 · The first header row is **Occupancy**. On a business date of 2026-10-05 each night cell of the 14-night window shows `0%`. Fixture stays in that window (HTL-026, HTL-027) have no season, so the seed does not claim them.
- [ ] E3 · Free rows, in type order, read **Standard Double free** `10`, **Twin free** `6`, **Family free** `5`, **Suite free** `3`, on every night of the 14-night window. Counts are `docs/requirements/examples/hotel-seed-data.json`.
- [ ] E4 · **Standard Double** lists Room 101, 102, 103, 104, then 201–204, then 301 and 302. **Suite** lists Room 108, 208, and 308. Each row shows the room label and the code.
- [ ] E5 · KPIs read Occupancy `0%`, Free room-nights `336` (24 rooms × 14 nights), Nights fully sold `0`. Nights below threshold is the API figure, not a number typed in the panel.
- [ ] E6 · `document.documentElement.scrollWidth` is not greater than `document.documentElement.clientWidth`. The timeline’s own scroller (`.night-scroll`) may scroll.

## Notes
Yacht layout is not a page to test here. `http://localhost:3001/rms/reservations/yacht-layout` redirects to this calendar. Sidebar has no Yacht Layout item. E3 and E5 (every room free, 336 free room-nights) hold on a business date of 2026-10-05, the same window as E2. Seeded stays sit in March–September 2026.
