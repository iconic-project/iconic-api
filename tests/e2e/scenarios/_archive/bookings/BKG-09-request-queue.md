# BKG-09 · Request queue

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
The two seeded requests must show a live SLA. Confirm claims the cabin as PENDING_PAYMENT. Release needs a reason and frees the cabin without auto-cancelling a *different* request (OPS-007).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/booking-requests`. Date range **All dates**.
2. Read the notice, the badge, both seeded rows (hold remaining and Contact SLA), and the **Charter enquiries** panel below the queue.
3. On ANK-R-2026-0041 click `Confirm · send deposit link`. Confirm modal: read the body. The leftover sentence `The deposit link is sent when payments arrive (Sprint 5).` is still on screen (do not invent a new sentence). Submit `Confirm · send deposit link`.
4. On ANK-R-2026-0042 click `Release`. Modal `Release this request's cabin hold`, reason `E2E release 0042`. `Record`.
5. Open `/rms/reservations/bookings` and find the pending-payment row for ANK-R-2026-0041. Open Calendar Year 2027: ANAMARA Suite 04 on 21 Nov and Suite 05 on 28 Nov. Scroll to **Deleted & released — audit**.

## Expected
- [ ] E1 · Two seeded rows (0041, 0042). No web `ANK-R-` yet (those appear after WEB-07). Badge **2**. 0041 SLA `19h` (green / ok). 0042 SLA `SLA BREACH — 26h` (coral). SLA hours are relative to `now()`. Holds are long-lead: expiry is 18:00 Galápagos on the 5th business day after the submission day (that day does not count), shown in hours while under 72. 0041 (submitted 5 h ago) still reads `45 business hours` when checked before that day's 09:00 open. 0042 (submitted 50 h ago) reads the remainder, not 45. On a weekday morning before 09:00, with submission on the evening two calendar days earlier, that remainder is `27 business hours`. The hour count moves as business time elapses.
- [ ] E2 · Notice includes `48 business hours near-term / 5 business days long-lead` and `within 24 hours`, and `No request is ever auto-cancelled without team review`. Sprint 8 copy also says web bookings arrive here as REQUESTED. **Charter enquiries** empty: `No charter enquiries.` (engine rows are OFF-04). ⚠ UNVERIFIED — i18n `requests.notice` + list `meta.rules`.
- [ ] E3 · Confirm 0041: toast `Request confirmed`. Row gone. Badge **1**. Bookings list shows a `PENDING PAYMENT` row still identified as `ANK-R-2026-0041`. `bookings.reference` stays null. The `ANK-` reference (next draw `ANK-2026-0022`) is assigned only when the booking reaches `CONFIRMED` (G3). This confirm stops at `PENDING PAYMENT`. Toast i18n `bookings.confirmedToast`.
- [ ] E4 · Release 0042: toast `Hold released, cabin returned to inventory.` Queue empty, badge **0**. Audit Action `Request released — hold returned to inventory`, Reason `E2E release 0042`. ⚠ UNVERIFIED — i18n `requests.releasedToast`; API `what`.
- [ ] E5 · Calendar: Suite 04 21 Nov is a pending/sold cell (0041 confirmed). Suite 05 28 Nov is `·` (Available).

## Notes
Task 19-07: the queue column shows `copy` (stay, nights, room type). The API does not send `departure` or `cabin_label`.

Do not run `inventory:expire-hold` in this scenario (that is BKG-10). After reset the queue is still the two staff-seeded requests; web engine requests are WEB-07. The leftover confirm-modal sentence `The deposit link is sent when payments arrive (Sprint 5).` may still be on screen — do not invent a new sentence. Confirm does not draw `ANK-2026-0022`; that draw waits for `CONFIRMED`.
