# EXT-05 · Cruise paid, extra unpaid past T−120: not OVERDUE
- **Tags:** sprint-6, extras
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
OVERDUE is cruise-outstanding only (I9). An unpaid extra after the due date must not flip the pill. PAY-08 still uses 0018, whose cruise balance is outstanding.

## Steps
1. Sign in as Carolina. Open ANK-2026-0005 (`FULLY PAID`, cruise outstanding 0). **Extras** tab. Add flights × 1 (set Qty `1`, Rate `420`). `Add to booking`.
2. Header stays `FULLY PAID`. Balance > 0. Confirm there is no `OVERDUE` pill.
3. From `iconic-api`, with the running compose project: `docker compose exec app sh -c "php artisan iconic:set-overdue-fixture ANK-2026-0005"`. Do **not** put this in `reset.sh`.
4. Refresh the bookings list (All dates) and reopen 0005.

## Expected
- [ ] E1 · After the extra: Balance due `USD 420`. Status pill `FULLY PAID`. No `OVERDUE`.
- [ ] E2 · After the fixture: still no `OVERDUE` on the list or the header. Status stays `FULLY PAID`. Overdue-only filter does **not** show 0005.
- [ ] E3 · Contrast (do not require a second fixture here): PAY-08’s `iconic:set-overdue-fixture` on ANK-2026-0018 (cruise outstanding) **does** show OVERDUE.

## Notes
`set-overdue-fixture` writes `balance_due_date_override` to yesterday. `isOverdue()` still needs CONFIRMED / ON_HOLD_AGENCY **and** cruise outstanding > 0. Extras never inflate that flag.
