# AUTO-02 · Switching a message off stops the mail, not the alert or the task
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B18
- **Users:** Carolina
- **Start:** reset

## Why
The catalogue switch stops one email. The overdue flag, the alert, and the task still happen.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/engine/automations`.
2. Turn off **Balance reminder — 21 days**. Confirm needs a reason. Type `E2E AUTO-02`. Turn off.
3. From `iconic-api`, with the running compose project:
   ```
   docker compose exec app sh -c "php artisan iconic:set-overdue-fixture"
   docker compose exec app sh -c "php artisan iconic:flag-overdue"
   docker compose exec app sh -c "php artisan iconic:alerts"
   docker compose exec app sh -c "php artisan iconic:documents-due"
   ```
4. Reload Automations. Read the balance-reminder row and the overdue alert row (**Overdue payment**).
5. Open `http://localhost:3001/crm/engine/alerts` and `http://localhost:3001/crm/sales/tasks`. Look for ANK-2026-0018.
6. Search Mailpit for a balance-reminder message about ANK-2026-0018 after step 3.

## Expected
- [ ] E1 · The disabled row shows reason `E2E AUTO-02` and Carolina.
- [ ] E2 · Overdue payment has no toggle. Its locked reason is `The rule behind this message must not depend on a switch.`
- [ ] E3 · ANK-2026-0018 has the overdue alert and the overdue task.
- [ ] E4 · Mailpit has no balance-reminder message for that booking after the switch.

## Notes
`iconic:set-overdue-fixture` is the same command PAY-08 uses. Do not add it to `reset.sh`.
