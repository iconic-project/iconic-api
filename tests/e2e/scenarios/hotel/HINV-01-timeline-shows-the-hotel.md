# HINV-01 · Timeline shows the hotel
- **Tags:** sprint-17, hotel, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The calendar is rooms by night. A hotel seed with no claims must show every room under its type, a week of nights, and an occupancy row, without the page body scrolling sideways.

## Steps
1. The seed is Hotel Demo. Do not accept ANAMARA as this scenario.
2. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/calendar`.
3. The property control shows **Hotel Demo**. Click **Today**. Leave **14 nights** selected.
4. Read the occupancy row, the four free rows, and the room groups.

## Expected
- [ ] E1 · Property is **Hotel Demo**. Chips **14 nights** and **31 nights** are present. **14 nights** is on.
- [ ] E2 · The first header row is **Occupancy**. On a business date of 2026-10-05 the 14-night window is `4%` on 5–6 Oct (Room 308) and 12–16 Oct (Room 101), and `0%` on the other nights. HTL-029 stays unseeded: the seeder cannot reach `ON_HOLD_AGENCY`.
- [ ] E3 · Free rows, in type order: **Standard Double free** is `9` on 12–16 Oct and `10` otherwise. **Twin free** is `6` and **Family free** is `5` every night. **Suite free** is `2` on 5–6 Oct and `3` otherwise. Counts are `docs/requirements/examples/hotel-seed-data.json`.
- [ ] E4 · **Standard Double** lists Room 101, 102, 103, 104, then 201–204, then 301 and 302. **Suite** lists Room 108, 208, and 308. Each row shows the room label and the code. Room 308 is occupied 5–6 Oct. Room 101 is occupied 12–16 Oct.
- [ ] E5 · KPIs read Occupancy `2%`, Free room-nights `329` (336 − 7 sold nights), Nights fully sold `0`. Nights below threshold is the API figure, not a number typed in the panel.
- [ ] E6 · `document.documentElement.scrollWidth` is not greater than `document.documentElement.clientWidth`. The timeline’s own scroller (`.night-scroll`) may scroll.

## Notes
Yacht layout is not a page to test here. `http://localhost:3001/rms/reservations/yacht-layout` redirects to this calendar. Sidebar has no Yacht Layout item. E3 and E5 hold on a business date of 2026-10-05, the same window as E2. High season now runs through 19 December, so HTL-026 and HTL-027 are seeded in that window. HTL-029 is not.
