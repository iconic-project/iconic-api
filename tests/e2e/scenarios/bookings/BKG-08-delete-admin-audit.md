# BKG-08 · Delete is admin-only and audited
- **Tags:** sprint-4, bookings
- **Priority:** P2
- **Users:** Mateo, Carolina
- **Start:** reset

## Why
`bookings.delete` is Admin. Soft-delete needs a reason and appears on Deleted & released.

## Steps
1. Sign in as `mateo@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0003. Read Delete.
2. Sign out. Sign in as Carolina in a fresh context. Open ANK-2026-0003. Click `Delete (admin only)`.
3. Modal title `Delete reservation ANK-2026-0003`. Leave Reason empty — `Record` stays disabled. Type `E2E delete 0003`. `Record`.
4. Scroll to **Deleted & released — audit**.

## Expected
- [ ] E1 · Mateo: Delete is disabled (`Delete (admin only)`). He has no `bookings.delete`. ⚠ UNVERIFIED — button still rendered, disabled; task 07 did not re-run Mateo.
- [ ] E2 · Carolina: modal `Delete reservation ANK-2026-0003`, `Reason (required)`. ⚠ UNVERIFIED — i18n `bookings.deleteTitle`.
- [ ] E3 · Toast `Reservation deleted`. ANK-2026-0003 is gone from the list. ⚠ UNVERIFIED — i18n `bookings.deletedToast`.
- [ ] E4 · Audit row: Action `Reservation deleted` (or API `what`), Reason `E2E delete 0003`, Booking ANK-2026-0003, Who Carolina M. ⚠ UNVERIFIED — audit `row.what` vs i18n `history.events.bookingDeleted`.

## Notes
One user per context. Mateo first so the seed row is still there.
