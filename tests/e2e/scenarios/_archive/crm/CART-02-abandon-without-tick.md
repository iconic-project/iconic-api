# CART-02 · Abandon without the tick stores nothing
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Guest
- **Start:** reset

## Why
Without the tick, checkout does not keep the address. The abandon event stays anonymous. Nothing is sent.

## Steps
1. **Guest.** Dismiss the analytics bar. Open `/book/details` for 2 adults, 7 Nov 2027 ANAMARA, Suite 03. Email `e2e.cart02@iconic.test`. First name `E2E`. Leave the marketing box unticked. Do not submit. Leave the page so `pagehide` runs.
2. From `iconic-api`: `docker compose exec app sh -c "php artisan iconic:journeys"`.
3. Search Mailpit for `e2e.cart02@iconic.test`.

## Expected
- [ ] E1 · No contact for `e2e.cart02@iconic.test`. No MARKETING register row. No enrolment.
- [ ] E2 · Mailpit has no message to that address.
- [ ] E3 · An `abandon_cart` event exists with `contact_id` null.

## Cross-checks
- `bin/db-check.sh 'App\Models\Contact::query()->where("email","e2e.cart02@iconic.test")->count()'` → `0`.
- `bin/db-check.sh 'App\Models\BehaviouralEvent::query()->where("name","abandon_cart")->whereNull("contact_id")->count()'` → at least `1`.

## Notes
Nurture may be off. The unticked path must not enrol either way. Do not tick the box.
