# UNSUB-01 · The unsubscribe link withdraws, suppresses, and exits
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Every marketing message carries a one-click unsubscribe. One click withdraws consent, suppresses the contact, and exits marketing enrolments. A second click changes nothing.

## Steps
1. **Carolina.** Open `http://localhost:3001/crm/marketing/journeys`. Turn **Nurture to Request — D2C** on.
2. **Guest.** Dismiss the analytics bar. Reach `/book/details` (2 adults, 7 Nov 2027 ANAMARA, Suite 03). Email `e2e.unsub01@iconic.test`. First name `E2E`. Tick `Iconic may email you news and expedition ideas. You can stop these at any time from the link in those emails. Unticking this box does not withdraw a request already sent. Wording is pending (LEG-002).` Leave the page so the browser fires `pagehide`.
3. From `iconic-api`: `docker compose exec app sh -c "php artisan iconic:journeys"`.
4. Open the welcome message in Mailpit. Open its unsubscribe link on `http://localhost:3000`.
5. Click **Unsubscribe**. Reload the same URL. Click is not offered again.
6. **Carolina.** Open the contact. Read consent, **Journeys**, and Segments → Suppressed.

## Expected
- [ ] E1 · The page first shows `Stop marketing email from Iconic.` and the button **Unsubscribe**.
- [ ] E2 · After the click: `You will no longer receive marketing email from Iconic.` and `Messages about an existing booking still arrive.` Reload shows the same confirmation and no button.
- [ ] E3 · A second POST does not add a register row. Marketing consent is withdrawn. The enrolment exit reason is `unsubscribed`. The contact is on Suppressed.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyEnrolment::query()->where("contact_id", App\Models\Contact::query()->where("email","e2e.unsub01@iconic.test")->value("id"))->where("status","EXITED")->where("exit_reason","unsubscribed")->count()'` → at least `1`.

## Notes
The link is the only place the token appears. Do not copy the token into the report. Do not send this message to a real address.
