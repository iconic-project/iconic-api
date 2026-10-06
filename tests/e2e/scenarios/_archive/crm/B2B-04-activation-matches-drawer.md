# B2B-04 · An activation enrolment matches the journeys drawer
- **Tags:** sprint-15, crm
- **Priority:** P1
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
The B2B Partners page and the Journeys enrolments drawer read the same `journey_enrolments` row. A fresh seed has the journey off and no enrolment. Approving a pending agency after the journey is on is what creates the row.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/journeys`.
2. Turn **B2B Partner Activation** on. The confirm quotes `The approved agency agreement.` Click **Turn on**.
3. Open `http://localhost:3001/rms/commercial/b2b`. In **Registration requests — agent portal**, on Andes Luxe Travel, click **Approve**.
4. Open `http://localhost:3001/crm/sales/b2b-partners`. Read the Andes Luxe Travel journey cell: status, step name, and next due.
5. Return to Journeys. On **B2B Partner Activation**, click **Enrolments**. Find the contact for Andes Luxe Travel (P. Ibáñez). Read status, step, and next due.

## Expected
- [ ] E1 · Before step 2 the journey is inactive, and Andes Luxe Travel is `PENDING` with a matched contact and no enrolment.
- [ ] E2 · After approve, the toast is `Agency approved. The portal invitation has been sent.`
- [ ] E3 · The B2B row shows status `ACTIVE`, step `Welcome + rate agreement and materials`, and a next-due value.
- [ ] E4 · The enrolments drawer shows the same status, the same step name, and the same next due for that contact.

## Cross-checks
- `bin/db-check.sh 'App\Models\Journey::query()->where("key","b2b_partner_activation")->first()->enrolments()->count()'` → `1`.
- The enrolment's `contact_id` is the contact whose email is the Andes Luxe Travel agency email (`p.ibanez@andesluxe.—` in `seed-data.json`).

## Notes
Do not seed this enrolment. `JourneysSeeder` leaves the journey inactive, and `DemoAgenciesSeeder` does not dispatch `AgencyApproved`. `JourneyEngine::enrol()` does nothing while the journey is off, so step 2 has to happen before step 3. The next due is enrolment plus 0 hours (`JourneysSeeder` step 1). Compare the two screens. Do not type a clock time.
