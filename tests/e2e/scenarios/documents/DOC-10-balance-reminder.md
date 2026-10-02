# DOC-10 · Balance reminder: documents-due once, then nothing
- **Tags:** sprint-7, documents
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Reminders go out on the effective due date minus `payments.balance_reminder_days` (21, then 7) only while the cruise balance is open (J7 / I9). A second run of the daily command must send nothing (delivery key).

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview** → **Billing** → set Email `e2e.doc10@iconic.test`. Save. (The command will BLOCK with no usable address.)
2. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
3. From `iconic-api`, with the running compose project:
   ```
   docker compose exec app sh -c "php artisan iconic:set-reminder-fixture ANK-2026-0003"
   docker compose exec app sh -c "php artisan iconic:documents-due"
   ```
   Do **not** put the fixture in `reset.sh`.
4. ```
   tests/e2e/bin/mail-find.sh --to e2e.doc10@iconic.test --subject "Your Iconic balance — due"
   ```
   Record the full subject (it includes the due date).
5. Run `php artisan iconic:documents-due` again. Search Mailpit for a second reminder.

## Expected
- [ ] E1 · Fixture prints that `balance_due_date_override` is Galápagos today + 21 days. Command refuses outside `local` / `testing`.
- [ ] E2 · First `documents-due`: one reminder in Mailpit. Subject `Your Iconic balance — due {YYYY-MM-DD}` (that due date). Body states the cruise balance (`USD 23,940`) and the due date, and includes an http(s) **Complete your reservation** URL (`{ENGINE_URL}/complete/{token}`). ⚠ UNVERIFIED — DeliverySubject::forReminder + catch-up “smallest eligible N” = 21; complete link is Sprint 8.
- [ ] E3 · Documents tab: reminder 1 status `SENT`. Reminder 2 (7 days) is still `SCHEDULED` (not yet eligible).
- [ ] E4 · Second `documents-due`: `Sent 0 document(s).` (or no new Mailpit message). Idempotency key `reminder:{booking_id}:{due}:{21}`.

## Notes
`iconic:set-reminder-fixture` is the Task 08 local/testing helper (same family as `inventory:expire-hold` / `iconic:set-overdue-fixture`). If the command is missing, add it on `e2e/sprint-07` — do not write the override through `db-check.sh`. 0003 cruise outstanding is `USD 23,940`; extras must not be required for this reminder.
