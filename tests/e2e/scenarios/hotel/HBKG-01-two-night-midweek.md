# HBKG-01 · Create a 2-night midweek stay
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A staff booking is a room for a stay. Two midweek nights in a published season quote, save, and show on the bookings list with no departure.

## Steps
1. Hotel seed (the default). Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`.
2. Click **＋ New reservation**. The dialog title is **New booking**.
3. Check-in `2026-12-21`, check-out `2026-12-23`. Room type **Standard Double**. Rate plan **Best available**. Room **Room 104**. Adults `2`. Leave children empty.
4. Read **Total**. Click **Create booking**.

## Expected
- [ ] E1 · The dialog quotes a total before save. Create does not ask for a departure or a cabin.
- [ ] E2 · The new row is on the bookings list. Stay `2026-12-21` → `2026-12-23`, type Standard Double, room 104, status pending payment. The row total equals the quoted total.
- [ ] E3 · `bin/db-check.sh` for that booking: `departure_id` is null, `nights` is 2, `check_in` is `2026-12-21`.

## Notes
21–23 Dec 2026 is Monday–Wednesday inside the fixture Peak season. Room 104 is free then (HTL-009 is June). Do not type a price; use the quoted total.
