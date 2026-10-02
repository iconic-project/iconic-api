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
1. **Ada.** Sign in at `http://localhost:3002/login` (`ada@portal.test`, password `password`). Open `http://localhost:3002/availability`. Set From `2027-11`, To `2027-11`, yacht `ANAMARA`, and click `Show`.
2. On **7 Nov 2027** ANAMARA, click `Request`. Category `Suite`. One cabin, 1 adult, 0 children. Client name `E2E Pay`. Client email `e2e-pay01@portal.test`. Tick `The client of record is the end guest.` Click `Send request`.
3. Open `http://localhost:3002/requests`. Open the new request (`data-request-drawer`). Click **Pay deposit**.
4. Do not enter a card. Leave the hosted page. From `iconic-api` run `tests/e2e/bin/replay-stripe-checkout.sh ANK-R-2026-0043`.
5. **Carolina**, separate context. Open the booking from `http://localhost:3001/rms/reservations/booking-requests` or the bookings list for `ANK-R-2026-0043`. Read status, the payments tab, and the history timeline.

## Expected
- [ ] E1 · On a fresh reset the new reference is `ANK-R-2026-0043`. The request drawer shows **Pay deposit** and no card field.
- [ ] E2 · Pay deposit navigates to the hosted payment URL. The drawer has no card input.
- [ ] E3 · After replay, the booking status is `CONFIRMED`. The payment is settled (`PaymentStatus::Settled`), same outcome as a staff deposit link.
- [ ] E4 · The history timeline shows actor `Ada Agent via portal` on `payment_link.created`.

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first()->paymentLinks()->value("created_by")'` → null.
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first()->paymentLinks()->first()->status->value'` → `PAID`.
- `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event","payment_link.created")->latest("id")->value("actor_label")'` → `Ada Agent via portal`.

## Notes
Do not use `ANK-2026-0007` for this deposit. That booking is already confirmed and the portal offers Pay balance. Empty Stripe keys: FakeStripe and `replay-stripe-checkout.sh`. Never live mode. Record **replay** if a run report is written later. This scenario creates the request, so `ANK-R-2026-0043` is correct only when reset has not already issued that reference.
