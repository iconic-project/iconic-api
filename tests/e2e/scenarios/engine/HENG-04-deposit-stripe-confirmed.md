# HENG-04 · Online deposit confirms the booking
- **Tags:** sprint-20, engine
- **Priority:** P1
- **Batch:** B25
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Pay the deposit online, then the Stripe test replay, and the stay booking is confirmed.

## Steps
1. **Guest.** Same search as HENG-03 (21–25 Dec 2026, 2 adults, 1 room). Click `Analytics off` first. Choose **Twin**, plan **Best available**, `Continue`.
2. First name `E2E`, last name `Deposit`, email `e2e.heng04@iconic.test`. Choose `Pay the deposit now`. Tick every declaration. Click `Hold these rooms`, then `Confirm`.
3. The browser leaves for the hosted checkout URL. Do not enter a card. Leave that page.
4. **Carolina.** Find the new request on `http://localhost:3001/rms/reservations/booking-requests`. Note its reference.
5. From `iconic-api` run `tests/e2e/bin/replay-stripe-checkout.sh` with that reference. Reload the booking.

## Expected
- [ ] E1 · Confirm navigates to a hosted checkout URL. The engine page has no card field.
- [ ] E2 · After replay, the booking status is `CONFIRMED`. The stay is still 21 Dec 2026 to 25 Dec 2026. `departure_id` is null.
- [ ] E3 · Replaying the same reference again does not add a second settled deposit.

## Notes
Empty Stripe keys use FakeStripe. Never live mode. The reference is the one this run just created. Do not reuse a seeded `ANK-R-` number.
