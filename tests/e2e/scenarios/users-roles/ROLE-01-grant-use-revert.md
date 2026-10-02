# ROLE-01 · Grant, use, revert a permission
- **Tags:** sprint-1, users-roles
- **Priority:** P1
- **Users:** Carolina · Lucía
- **Start:** reset
- **Needs:** two browser contexts

## Why
Permission changes apply on the next request, not on the cookie. History must name the catalogue label.

## Steps
1. Context B: sign in as `lucia@iconic.test` / `password`. Stay signed in on Calendar.
2. Context A: sign in as `carolina@iconic.test` / `password`. Open `/rms/admin/permissions`.
3. In the **Sales Exec** column, find the row **Delete reservation** (`bookings.delete`). Toggle it from `✗ No` to granted (`✓ Yes`). Click `Save` on the sticky bar.
4. Open **History** via the `···` button (`Role actions`) on the **Sales Exec** column header — not a row action.
5. Context B (Lucía): trigger a new request (navigate to `/rms/reservations/bookings` and back, or reload). Do **not** open `/api/auth/me` as a panel page (that returns HTML). Either inspect the DevTools Network request to `http://localhost:8000/api/auth/me` after that navigation, or run the Cross-check below.
6. Context A: toggle **Delete reservation** back to `✗ No`. `Save`. Open History again (same `···` menu).

## Expected
- [ ] E1 · After the grant, toast `Roles updated`.
- [ ] E2 · Sales Exec history newest sentence is `Permissions changed · added: Delete reservation`.
- [ ] E3 · Lucía’s next authenticated request includes `bookings.delete` (network `http://localhost:8000/api/auth/me` or the Cross-check).
- [ ] E4 · After revert, history has a second permissions entry `Permissions changed · removed: Delete reservation` (two permission-change rows total).

## Cross-checks
- After the grant: `tests/e2e/bin/db-check.sh 'App\Models\User::query()->where("email","lucia@iconic.test")->first()->hasPermission(\App\Enums\Permission::BookingsDelete)'` → `true`
- After the revert: the same expression → `false`

## Notes
The Sprint 1 AC said “Delete bookings”. The catalogue and screen use **Delete reservation**.
