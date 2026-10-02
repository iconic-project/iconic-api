# PAY-08 · OVERDUE flag and OPS-007 extension
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
OVERDUE is derived. Nothing auto-cancels. Granting an extension with a reason must clear the flag and write History.

## Steps
1. From `iconic-api`, with the running compose project: `docker compose exec app sh -c "php artisan iconic:set-overdue-fixture"`. Do **not** put this in `reset.sh`.
2. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
3. Click **Overdue only**. Open ANK-2026-0018 (A. Fontaine).
4. Click `Grant extension`. Modal title `Grant extension (OPS-007)`. New due date = tomorrow (Galápagos). Reason `E2E extend 0018`. `Record`.
5. Read the list, the panel and History.

## Expected
- [ ] E1 · After the fixture, 0018 shows the `OVERDUE` pill on the list and in the header. Balance is coral. Overview warnbox `Balance overdue by 1 days — USD 23,940. OPS-007: the team decides; nothing is cancelled automatically.` Buttons `Grant extension` and `Cancel per policy`. Overdue-only hides every non-overdue row.
- [ ] E2 · Payments & Revenue Overdue KPI becomes `USD 23,940` (refresh `/rms/commercial/payments` if already open).
- [ ] E3 · After grant: toast `OPS-007 decision recorded`. OVERDUE pill gone. Warnbox gone. Status stays CONFIRMED.
- [ ] E4 · History includes the OPS-007 extension (`OPS-007 decision — extension granted · OVERDUE → CONFIRMED`) under Carolina with reason `E2E extend 0018`.

## Notes
0018 sails 12 Dec 2027; its stored due date is 14 Aug 2027, so it is not overdue until the fixture sets `balance_due_date_override` to yesterday. `iconic:flag-overdue` is optional — the pill is derived from the due date.
