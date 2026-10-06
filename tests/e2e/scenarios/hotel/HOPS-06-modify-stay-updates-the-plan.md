# HOPS-06 · Modify a stay and the document plan follows
- **Tags:** sprint-21, hotel, documents
- **Priority:** P2
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
Pre-arrival is measured from check-in. Moving the stay moves that date. The issued file already sent is left as it was.

## Steps
1. Sign in as Carolina. Open HTL-027. **Documents** tab. Read the date on **Pre-arrival information**.
2. On the booking, set **Arriving between** to `2026-10-13` and **Departing between** to `2026-10-18`. Type reason `E2E shift one night`. Click **Preview**, then **Confirm**.
3. Open **Documents** again. Read the pre-arrival date. Open **Preview** on **Booking Confirmation & Invoice** if a new version is offered.

## Expected
- [ ] E1 · Before the change, pre-arrival is dated from check-in `2026-10-12` (the trigger still reads `T−` plus the rule's days).
- [ ] E2 · After confirm, check-in on the booking is `2026-10-13` and check-out is `2026-10-18`.
- [ ] E3 · The pre-arrival row's date is one day later than the date read in step 1.
- [ ] E4 · A confirmation preview opened after the change shows check-in `2026-10-13 · 15:00`. An already sent file is not rewritten in place; a new version is a separate row.

## Notes
Room 101, confirmed. Reason is required. If Preview refuses the new nights, record the server message and stop. Do not pick a different room.
