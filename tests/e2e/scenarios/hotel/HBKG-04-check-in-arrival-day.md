# HBKG-04 · Check in on the arrival day
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Check-in opens on the arrival day, at any time that day. The day before, it is refused.

## Steps
1. Hotel seed. Sign in as Carolina. Create a fully paid Standard Double on Room 204, check-in `2026-12-21`, check-out `2026-12-23` (quote, create, record the balance). Open the booking.
2. While the business date is before `2026-12-21`, read the front-desk actions. **Check in** is not offered. If you call check-in anyway, the API message is `Check-in opens on the arrival day.`
3. When the business date is `2026-12-21`, open the same booking. **Check in** once in the early morning and, on a second stay the same day, again late in the evening.

## Expected
- [ ] E1 · Before the arrival day, check-in is refused. The status stays fully paid.
- [ ] E2 · On the arrival day, check-in succeeds both early and late. Status becomes **In house**. `checked_in_at` is the time you sent, still on `2026-12-21` in Pacific/Galapagos.
- [ ] E3 · The room stays claimed for both nights. `departure_id` stays null.

## Notes
Step 3 needs the business date to be the arrival day. If the clock is earlier, record E1 and stop. Do not change the stay to force the clock.
