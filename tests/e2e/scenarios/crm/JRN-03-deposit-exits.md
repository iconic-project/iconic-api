# JRN-03 · Paying the deposit exits Request to Deposit
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Guest + Carolina
- **Start:** continues from JRN-02

## Why
A settled deposit is the exit. The next step must not send.

## Steps
1. Before the next send is due, run `tests/e2e/bin/replay-stripe-checkout.sh ANK-R-2026-0043`.
2. **Carolina.** Open the contact. Wait until **Journeys** shows the enrolment left ACTIVE. Horizon must be up. Wait for that text, at most 15 seconds.
3. Run `tests/e2e/bin/setup.sh journey-due <id>` once more.
4. Check Mailpit for a new message to `e2e.jrn01@iconic.test` after step 3.

## Expected
- [ ] E1 · Status is `EXITED`. Exit reason is `payment.received`.
- [ ] E2 · The further `journey-due` does not send. No new message to that address.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneyEnrolment::query()->find(<id>)'` → `status` `EXITED`, `exit_reason` `payment.received`.

## Notes
`SyncJourneys` is queued. The drawer is the visible condition. Do not mark the step failed because the row was still ACTIVE for a moment.
