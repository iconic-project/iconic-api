# TASK-02 · Overdue balance and the commission-cap hold each raise one task; the sweep does not duplicate them
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Those two conditions are tasks with a permission, not a second click that inserts another row.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/sales/tasks`. Filter kind **Commission cap**.
2. Find **ANK-2026-0021** (Meridian Voyages hold, `commission_approved` false, status ON HOLD AGENCY).
3. Read the task’s needs permission.
4. Filter kind **Overdue decision**. Read the payments Overdue figure on `/rms/commercial/payments`.
5. Run the sweep once more: `docker compose exec app sh -c "php artisan iconic:crm-tasks"`. Reload Tasks. Count both kinds again.

## Expected
- [ ] E1 · Exactly one open **Commission cap** task for ANK-2026-0021. The card shows `commissions.override_cap`. ⚠ UNVERIFIED — `DemoAgenciesSeeder` hold; the permission string is `Permission::CommissionsOverrideCap`, shown raw on the card.
- [ ] E2 · **Overdue decision** tasks equal the bookings the payments page already treats as overdue. Each card shows `bookings.overdue_decision`. If that figure is USD 0 and there is no OVERDUE booking, there is no overdue task. Do not invent a balance. ⚠ UNVERIFIED — fresh seed may have none.
- [ ] E3 · After the second sweep the two counts are unchanged.

## Notes
A second commission-cap task for 0021 is a **BUG**. An overdue task with no overdue booking is a **BUG**.
