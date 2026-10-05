# BKG-10 · Expired request hold

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
An expired HOLD frees the cabin and never cancels the request (G9). Confirm after expiry re-claims if the cabin is still free.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/booking-requests`. Confirm ANK-R-2026-0041 is in the queue and its hold is not expired.
2. From the iconic-api root, with the e2e compose project, run the documented command (not a `db-check` write):

   `COMPOSE_PROJECT_NAME=iconic-e2e docker compose exec app sh -c "php artisan inventory:expire-hold ANK-R-2026-0041"`

   (Same as `docker compose exec app sh -c "php artisan inventory:expire-hold ANK-R-2026-0041"` when `COMPOSE_PROJECT_NAME` is already `iconic-e2e`.)
3. Reload Booking Requests. Read the 0041 hold cell. Open Calendar Year 2027, ANAMARA Suite 04 on `21 Nov 2027`.
4. On 0041 click `Confirm · send deposit link` and confirm.

## Expected
- [ ] E1 · After the command, 0041 hold cell is `HOLD EXPIRED — CABIN NOT HELD`. Status stays `REQUESTED`. Badge still **2**. ⚠ UNVERIFIED — `formatHoldRemaining` expired branch; task 09/10 browser.
- [ ] E2 · Calendar: Suite 04 21 Nov is `·` (Available). Request remains in the queue.
- [ ] E3 · Confirm succeeds. Toast `Request confirmed`. Booking is `PENDING PAYMENT`. Calendar cell is no longer free (`PEND` or `0020`). ⚠ UNVERIFIED — next ANK and cell label.

## Notes
Task 19-07: the queue column shows `copy`. Confirm after expiry still re-claims the same room when it is free. A different free room of the type is offered on `POST /api/rms/requests/{id}/confirm/preview` and is not taken unless confirm sends that `room_id`. The panel confirm button does not yet show that alternative.

`inventory:expire-hold` is a **documented artisan command** for local/testing. It is **not** a `db-check` write (`db-check.sh` would refuse it). The job sets `expires_at` in the past and runs `ClaimService::releaseExpired()`. Confirm after expiry re-claims (G9).
