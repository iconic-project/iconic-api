# HCRM-03 · Journey email three days before arrival
- **Tags:** sprint-21, crm
- **Priority:** P2
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Ready to Depart sends final instructions on check-in minus three days. The anchor is arrival, which is check-in.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/journeys`. Find **Ready to Depart — pre-trip**.
2. Read the step **Final instructions — see you Sunday**.
3. In Mailpit, search the subject `Almost time! Final instructions`.
4. If an enrolment is already on that step, run `tests/e2e/bin/setup.sh journey-due <id>` once. Read the printed template key.

## Expected
- [ ] E1 · That step's timing is `T−3`.
- [ ] E2 · Before a due enrolment is advanced, Mailpit has no new final-instructions message for a stay whose check-in is more than three days ahead.
- [ ] E3 · When `journey-due` prints template key `arrival_instructions`, Mailpit has subject `Almost time! Final instructions for your arrival in San Cristóbal` for that contact. When the printed key is anything else, stop and class **SCENARIO**. Do not move the enrolment by hand.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyStep::query()->where("template_key","arrival_instructions")->value("delay")'` → anchor `arrival`, amount `-3`, unit `days`.

## Notes
The clock still accepts the old anchor name `departure` and treats it as check-in. This step is stored as `arrival`. No seeded enrolment is required to be on this step. E1 and the cross-check are the schedule. E3 runs only when an enrolment is already there.
