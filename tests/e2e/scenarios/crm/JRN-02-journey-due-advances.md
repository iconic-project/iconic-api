# JRN-02 · journey-due sends the next step and records the template version
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Carolina
- **Start:** continues from JRN-01

## Why
The runner sends a step only when it is due. The send stores the published template version. The contact drawer does not show that version.

## Steps
1. Stay signed in as Carolina. Read the enrolment id for `ANK-R-2026-0043` from JRN-01's cross-check.
2. Run `tests/e2e/bin/setup.sh journey-due <id>`.
3. Run `tests/e2e/bin/setup.sh journey-due <id>` again.
4. Open the contact drawer. Read **Journeys**.

## Expected
- [ ] E1 · The first call advances the 4-hour task. Position moves on. No new `journey_sends` row. The printed `template_version` stays `1`, from the acknowledgement already sent.
- [ ] E2 · The second call sends `deposit_link`. The printed `template_version` is `1`.
- [ ] E3 · The drawer shows template key `deposit_link` and a `Sent {when}` line. It does not show the version number.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneySend::query()->where("template_key","deposit_link")->where("journey_enrolment_id", <id>)->value("template_version")'` → `1`. Approval on that published version is `Sprint 14: initial journey template`.

## Notes
One `journey-due` runs `iconic:journeys` once. The day-1 send is not due after the task until the helper moves `next_due_at` again.
