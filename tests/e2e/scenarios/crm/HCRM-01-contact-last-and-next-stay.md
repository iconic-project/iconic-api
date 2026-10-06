# HCRM-01 · Contact shows last and next stay
- **Tags:** sprint-21, crm
- **Priority:** P1
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
The contact drawer reads stays from bookings. Last check-out and next check-in are those dates. Passport and date of birth stay out of the drawer.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/sales/contacts`. Search `Harrison`. Open **Harrison & Whitfield**.
2. Read the **Stays** line.

## Expected
- [ ] E1 · The line matches `{stays} stays · {nights} nights` with stays at least 1.
- [ ] E2 · It includes **Next check-in** and the date of ANK-2026-0003's check-in (`7 Nov 2027` in the short date format). It does not label that date as a departure.
- [ ] E3 · The drawer text has no passport number, date of birth, or nationality.

## Cross-checks
- `bin/db-check.sh 'App\Models\Contact::query()->where("name","Harrison & Whitfield")->withDerived()->first()'` → `next_stay_check_in` is `2027-11-07`. `last_stay_check_out` is null when no sold stay has a check-in on or before today.

## Notes
Sold statuses only. A future check-in is the next stay, not the last one.
