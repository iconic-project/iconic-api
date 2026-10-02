# CART-01 · A ticked checkout abandon keeps the contact and enrols recovery
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Guest + Carolina
- **Start:** reset

## Why
An address typed at checkout is kept only when the person ticks the box. That tick enrols cart recovery. It does not send yet.

## Steps
1. As Carolina, turn **Nurture to Request — D2C** on at `http://localhost:3001/crm/marketing/journeys`, then sign out. The runner will not enrol while the journey is off.
2. **Guest.** Dismiss the analytics bar. Open `/book/details` for 2 adults, 7 Nov 2027 ANAMARA, Suite 03. Email `e2e.cart01@iconic.test`. First name `E2E`. Tick the checkout marketing box (the LEG-002 sentence). Do not submit the booking. Leave the page so `pagehide` runs.
3. From `iconic-api`: `docker compose exec app sh -c "php artisan iconic:journeys"`.
4. As Carolina, open the contact and Segments is not required. Read **Journeys**.

## Expected
- [ ] E1 · A contact exists for `e2e.cart01@iconic.test`. It has no booking.
- [ ] E2 · One MARKETING register row, capture point `ENGINE_FORM`, version `v1 (pending LEG-002)`.
- [ ] E3 · An enrolment on branch `abandoned_checkout`, status `ACTIVE`. No cart email yet. The first cart step is due 24 hours after enrolment.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyEnrolment::query()->where("contact_id", App\Models\Contact::query()->where("email","e2e.cart01@iconic.test")->value("id"))->where("branch","abandoned_checkout")->where("status","ACTIVE")->count()'` → `1`.
- `bin/db-check.sh 'App\Models\Booking::query()->where("contact_id", App\Models\Contact::query()->where("email","e2e.cart01@iconic.test")->value("id"))->count()'` → `0`.

## Notes
The lead branch may also enrol. This scenario's contract is the cart branch. Do not call `abandoned-checkout` here. The screen is the path.
