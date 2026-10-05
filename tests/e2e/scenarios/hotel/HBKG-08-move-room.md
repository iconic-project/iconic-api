# HBKG-08 · Move room, timeline updates
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A stay that has not started can move to another room. The calendar follows the room. The move does not add a modification fee.

## Steps
1. Hotel seed. Sign in as Carolina. Create a pending-payment Standard Double on Room 101, check-in `2026-12-21`, check-out `2026-12-23`.
2. Open the booking. **Move room**. Pick Room 204. Reason `HBKG move`. Confirm.
3. Open the calendar on the week of 21 Dec 2026.

## Expected
- [ ] E1 · Move without a reason is refused.
- [ ] E2 · The booking room is 204. Status is unchanged. The total is unchanged.
- [ ] E3 · The timeline shows the stay on Room 204 for 21 and 22 Dec, and Room 101 is free those nights.

## Notes
Move is for a stay that has not started. An in-house guest is not offered **Move room**.
