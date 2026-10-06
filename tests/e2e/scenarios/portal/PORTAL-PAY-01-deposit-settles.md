# PORTAL-PAY-01 · An agent deposit link settles like a staff link
- **Tags:** sprint-15, portal
- **Priority:** P1
- **Batch:** B20
- **Users:** Ada Agent + Carolina
- **Start:** reset
- **Needs:** a second browser context for the portal

## Why
An agency user can open a deposit link for their own request. Paying it uses the same Stripe replay as a staff link. The booking becomes confirmed, and the history names the portal user.

## Steps
1. **Ada.** Sign in at `http://localhost:3002/login` (`ada@portal.test`, password `password`). Open `http://localhost:3002/availability`. Check-in **21 Dec 2026**, check-out **25 Dec 2026**, adults `2`. Click `Show`.
2. On **Family**, click `Request`. Leave the room type and the plan. Adults `2`. Client name `E2E Pay`. Client email `e2e-pay01@portal.test`. Tick `The client of record is the end guest.` Click `Send request`.
3. Open `http://localhost:3002/requests`. Open the new request (`data-request-drawer`). Click **Pay deposit**.
4. Do not enter a card. Leave the hosted page. From `iconic-api` run `tests/e2e/bin/replay-stripe-checkout.sh` with the reference from step 2.
5. **Carolina**, separate context. Open that booking from `http://localhost:3001/rms/reservations/booking-requests`. Read status, the payments tab, and the history timeline.

## Expected
- [ ] E1 · The request drawer shows **Pay deposit** and no card field. The reference is the one step 2 just created.
- [ ] E2 · Pay deposit navigates to the hosted payment URL. The drawer has no card input.
- [ ] E3 · After replay, the booking status is `CONFIRMED`. The payment is settled (`PaymentStatus::Settled`), same outcome as a staff deposit link.
- [ ] E4 · The history timeline shows actor `Ada Agent via portal` on `payment_link.created`.

## Cross-checks
- Substitute the reference from step 2. `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","<reference>")->first()->paymentLinks()->value("created_by")'` → null.
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","<reference>")->first()->paymentLinks()->first()->status->value'` → `PAID`.
- `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event","payment_link.created")->latest("id")->value("actor_label")'` → `Ada Agent via portal`.

## Notes
The request is the Family stay 21–25 Dec 2026. Replay the reference this run created. Do not reuse a seeded `ANK-R-` number. Empty Stripe keys: FakeStripe and `replay-stripe-checkout.sh`. Never live mode. Hotel seed does not create `ada@portal.test`. If sign-in has no such user, stop and class ENV.
