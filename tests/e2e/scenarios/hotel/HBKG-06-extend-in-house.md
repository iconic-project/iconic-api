# HBKG-06 · Extend an in-house guest
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
An in-house guest can stay extra nights. The preview prices the added nights. Confirming writes the new check-out.

## Steps
1. Hotel seed. On business date `2026-12-21`, have an in-house Standard Double on Room 304, check-in `2026-12-21`, check-out `2026-12-23`, fully paid, checked in.
2. **Modify stay**. Set check-out to `2026-12-25`. Leave the room. **Preview**, then **Confirm** with reason `HBKG extend`.

## Expected
- [ ] E1 · The preview shows a later check-out and a higher total. The total is the preview figure, not a number typed in the panel.
- [ ] E2 · After confirm, the stay is 21–25 Dec 2026 (4 nights) and the status is still **In house**.
- [ ] E3 · Room 304 is claimed through 24 Dec. History reason is `HBKG extend`.

## Notes
Needs the business date to be the arrival day so the guest can be in house. Room 304 is free in December in the seed (HTL-022 is August).
