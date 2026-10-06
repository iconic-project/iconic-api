# JRN-05 · Turning a journey off stops new enrolments
- **Tags:** sprint-14, crm
- **Priority:** P2
- **Batch:** B19
- **Users:** Carolina
- **Start:** reset
- **Needs:** a second browser context for the engine

## Why
An inactive journey refuses a new enrolment. An enrolment that already exists stays visible.

## Steps
1. **Carolina.** Open `http://localhost:3001/crm/marketing/journeys`. Turn **Request to Deposit — confirm the booking** on.
2. **Guest context.** Dismiss the analytics bar. Pay later on a free stay (HENG-03), email `e2e.jrn05a@iconic.test`.
3. From `iconic-api`: `docker compose exec app sh -c "php artisan iconic:journeys"`.
4. **Carolina.** Open Enrolments. Confirm one row. Turn the journey off. The confirm quotes `The booking request they submitted.` Click **Turn off**.
5. **Guest context.** A second pay later, email `e2e.jrn05b@iconic.test`, on a different cabin so the first hold is not the blocker. Suite 04 on the same departure if Suite 03 is held.
6. Run `php artisan iconic:journeys` again. **Carolina.** Reload Enrolments.

## Expected
- [ ] E1 · After step 3 the drawer has the first contact and not the second.
- [ ] E2 · After step 6 the first enrolment is still listed. The second email has no enrolment.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyEnrolment::query()->whereIn("contact_id", App\Models\Contact::query()->whereIn("email", ["e2e.jrn05a@iconic.test", "e2e.jrn05b@iconic.test"])->pluck("id"))->count()'` → `1`.

## Notes
The task table names Carolina because she owns the switch. The requests still come from the engine. Journeys start inactive. Do not leave Request to Deposit on at the end of this scenario if a later scenario on the same database would see it. This scenario starts from reset, so the next scenario resets again.
