# HENG-03 · Book now, pay later
- **Tags:** sprint-20, engine
- **Priority:** P1
- **Batch:** B25
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Pay later creates a request for the stay. The RMS queue shows that stay. The room stays held.

## Steps
1. **Guest.** Open `http://localhost:3000`. Click `Analytics off`. Click `Reserve now`. Search check-in **21 Dec 2026**, check-out **25 Dec 2026**, adults `2`, rooms `1`, then `Search`.
2. On **Standard Double**, rate plan **Best available**, rooms `1`. Click `Continue`.
3. First name `E2E`, last name `Paylater`, email `e2e.heng03@iconic.test`. Choose `Request, pay later`. Tick every declaration. Click `Hold these rooms`, then `Confirm`.
4. **Carolina**, other context. Open `http://localhost:3001/rms/reservations/booking-requests` and find the new reference.

## Expected
- [ ] E1 · Pay later stays in the reserve panel. The confirmation heading is `We have received your booking`. The reference is in that panel. The address stays `/`.
- [ ] E2 · Booking Requests shows that reference, status REQUESTED, check-in 21 Dec 2026, check-out 25 Dec 2026, room type Standard Double.
- [ ] E3 · `bin/db-check.sh` for that booking: `departure_id` is null, `nights` is 4, and an active hold covers the four nights 21–24 Dec 2026.

## Notes
`Hold these rooms` places the checkout hold. `Confirm` submits pay later. Tick every declaration the form shows (privacy, insurance, terms, cancellation). Guest context has no staff cookies.
