# PRIV-02 · An objection withdraws marketing, profiling and remarketing
- **Tags:** sprint-10, crm, sprint-14
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Completing an objection is the withdrawal. Transactional continues. The register count drops. An active marketing enrolment must stop before another marketing step sends.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/journeys`. Turn **Nurture to Request — D2C** on. The confirm quotes `engine: lead.captured · abandon_cart — marketing consent required`.
2. Run `tests/e2e/bin/setup.sh abandoned-checkout e2e.priv02@iconic.test`. Keep the printed enrolment ids. Do not run `iconic:journeys` again before Complete. The helper's own run is the only one.
3. Open `http://localhost:3001/crm/system/consent`. Read the Marketing contacts count.
4. **New request**: type **Objection**, `e2e.priv02@iconic.test`, channel **Email**.
5. Open it. Enter how it was verified. **Complete** with an outcome.
6. Reload the register. Open the contact and open **History**.
7. Run `tests/e2e/bin/setup.sh journey-due <id>` once for each enrolment id from step 2 that is still ACTIVE.
8. Open the contact again. Read **Journeys**. Check Mailpit for a message to `e2e.priv02@iconic.test` that arrived after step 5.

## Expected
- [ ] E1 · Marketing, profiling and remarketing are **NOT OPTED IN**. Each history row for that withdrawal has capture point `SUBJECT_REQUEST`.
- [ ] E2 · The Marketing contacts count is one lower than step 3.
- [ ] E3 · Transactional stays **ALWAYS ON**.
- [ ] E4 · After each `journey-due`, status is `SUPPRESSED` and the exit reason is `Marketing consent withdrawn.` No marketing message to that address arrives after Complete.

## Notes
Do not erase the contact here. `CompleteSubjectRequest` does not exit the enrolment by itself. The drawer can still show ACTIVE until `journey-due`. Unsubscribe is the path that exits immediately with reason `unsubscribed`.
