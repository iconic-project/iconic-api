# HBKG-05 · Early departure credits unused nights
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** continues from HBKG-04

## Why
Checking out before the check-out date shortens the stay. Unused nights are released and the total drops. No cancellation penalty is added.

## Steps
1. Continue from an in-house stay whose check-out is still ahead (HBKG-04's 21–23 Dec stay, on the business date `2026-12-22`).
2. Open the booking. **Check out**. The dialog asks for a reason because this is before the check-out date. Reason `HBKG early`. Confirm.
3. Read the stay, the total, and the calendar for Room 204 on 22 Dec.

## Expected
- [ ] E1 · Check-out without a reason is refused.
- [ ] E2 · With the reason, status is **Checked out**. The stay's check-out is the business date (`2026-12-22`), so one night remains. The new total is the preview total for that shorter stay, lower than the original quote.
- [ ] E3 · Room 204 on 22 Dec is free. History reason is `HBKG early`. No penalty line was added.

## Notes
Arrival-day check-out (no night left) is refused. This scenario leaves one night. If the business date is not `2026-12-22`, stop.
