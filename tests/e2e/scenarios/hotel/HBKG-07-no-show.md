# HBKG-07 · No-show releases following nights
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A no-show keeps the arrival night and releases the nights after it. The status becomes No-show. Nothing is cancelled by a job.

## Steps
1. Hotel seed. Fully paid Standard Double on Room 203, check-in `2026-12-21`, check-out `2026-12-24` (3 nights). Do not check in.
2. On the business date `2026-12-21`, after the no-show cutoff in the stay rules, open the booking. **No-show** is offered. Submit without a reason, then again with reason `HBKG no-show`.
3. Read the calendar for Room 203 on 21, 22, and 23 Dec.

## Expected
- [ ] E1 · No-show without a reason is refused.
- [ ] E2 · With the reason, status is **No-show**. The arrival night stays claimed. 22 Dec and 23 Dec on Room 203 are free.
- [ ] E3 · The booking was not cancelled. `departure_id` is null.

## Notes
The cutoff time is the published stay rule, not a number in this scenario. If **No-show** is absent, the business date is outside the window — stop.
