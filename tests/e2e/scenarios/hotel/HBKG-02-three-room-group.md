# HBKG-02 · Three-room group with different dates
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
One sale can hold several rooms, and each room can have its own dates. The group is one name and three bookings.

## Steps
1. Hotel seed. Sign in as Carolina. Open `/rms/reservations/bookings` and **＋ New reservation**.
2. Group name `HBKG two`. Add three rooms. Turn on **Different dates** for rooms 2 and 3.
3. Room 1: Standard Double, Room 102, check-in `2026-12-21`, check-out `2026-12-23`, adults 2.
4. Room 2: Standard Double, Room 202, check-in `2026-12-22`, check-out `2026-12-25`, adults 2.
5. Room 3: Standard Double, Room 302, check-in `2026-12-26`, check-out `2026-12-28`, adults 2.
6. Read **Total**. **Create booking**.

## Expected
- [ ] E1 · One quote covers all three rooms. The saved total equals that quote.
- [ ] E2 · The bookings list shows three rows in one group. Their stays are 21–23 Dec, 22–25 Dec, and 26–28 Dec 2026. Rooms are 102, 202, and 302.
- [ ] E3 · Each row has `departure_id` null. The group name is `HBKG two`.

## Notes
All three stays sit inside Peak (`2026-12-20`–`2026-12-31`). Those rooms are free in December in the hotel seed.
