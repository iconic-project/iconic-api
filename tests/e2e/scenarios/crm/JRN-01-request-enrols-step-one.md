# JRN-01 · An engine request enrols in Request to Deposit
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
A REQUESTED booking enrols on Request to Deposit. Step one sends. The count on the card is the live ACTIVE rows at that step.

## Steps
1. **Carolina.** Open `http://localhost:3001/crm/marketing/journeys`. Turn **Request to Deposit — confirm the booking** on. The confirm quotes `The booking request they submitted.` Click **Turn on**.
2. **Guest.** Dismiss the analytics bar (`Analytics off`, or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Same cabin path as WEB-07: 2 adults, 7 Nov 2027 ANAMARA, Suite 03, pay later. Email `e2e.jrn01@iconic.test`.
3. From `iconic-api`, with the running compose project: `docker compose exec app sh -c "php artisan iconic:journeys"`. Hour 0 is already due. Do not wait for the fifteen-minute schedule.
4. **Carolina.** Reload Journeys. Open **Enrolments** on Request to Deposit. Read the step counts.

## Expected
- [ ] E1 · Fresh reset's new request is `ANK-R-2026-0043`. Mailpit has the acknowledgement to `e2e.jrn01@iconic.test`.
- [ ] E2 · The enrolments drawer shows that contact. The count moves off **We have received your booking (acknowledgement)** onto **Sales exec personal note on the preferred channel** (`1 enrolled`).
- [ ] E3 · Other bookings, payments, guests, and documents keep the same ids they had after reset.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyEnrolment::query()->where("booking_id", App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->value("id"))->first()'` → status `ACTIVE`, position `2`.

## Notes
Journeys are inactive after reset. Turn this one on before the guest submits. Do not turn the others on.
