# PAY-09 · OPS-007 cancel per policy on an overdue paid booking
- **Tags:** sprint-5, payments
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Cancel-per-policy on an overdue booking that has a settled deposit must free the cabin and queue a refund at the right band. Automation never cancels (OPS-007).

## Steps
1. From `iconic-api`: `docker compose exec app sh -c "php artisan iconic:set-overdue-fixture"`.
2. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0018. Confirm it is OVERDUE.
3. Click `Cancel per policy`. Modal title `Cancel per policy (OPS-007)`. The hint is `Penalty computed, refund request queued for Director approval, cabin released.` Reason `E2E OPS-007 cancel 0018`. `Record`.
4. Open Calendar Year 2027, ANAMARA Suite 02 on `12 Dec 2027`. Open `/rms/operations/refunds`.

## Expected
- [ ] E1 · After cancel: toast `OPS-007 decision recorded`. Header `CANCELLED`. Cabin on 12 Dec 2027 ANAMARA Suite 02 is `·`.
- [ ] E2 · Refund Approvals shows ANK-2026-0018. Policy band `≥120 days → 5%`. Penalty `USD 1,330` (5 % of 26,600). Refund due `USD 1,330 of USD 2,660 paid`. SLA `15 BUSINESS DAYS` (or the remaining-days chip). Filter **Pending** keeps the row.
- [ ] E3 · History includes `OPS-007 decision — cancelled per policy · OVERDUE → CANCELLED` with reason `E2E OPS-007 cancel 0018`, plus `refund.requested` (penalty `USD 1,330`, refund `USD 1,330`).

## Notes
0018 is the overdue fixture booking and already has a settled deposit, so this is not the zero-paid path (`Nothing was paid, so nothing is owed.`). PAY-10 can reuse this request if run as `continues from PAY-09`; the P1 copy of PAY-10 cancels 0003 itself.
