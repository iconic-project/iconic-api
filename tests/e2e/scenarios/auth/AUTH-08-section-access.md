# AUTH-08 · Section access
- **Tags:** sprint-1, auth
- **Priority:** P1
- **Users:** CFO · Mateo · Lucía
- **Start:** reset

## Why
The panel must hide what the role cannot use, and the route guard must bounce forbidden URLs with the same toast.

## Steps
1. Sign in as `cfo@iconic.test` / `password`.
2. Confirm there is no RMS / CRM section switch. Type `/crm/sales/pipeline` in the address bar.
3. Sign out. Sign in as `mateo@iconic.test` / `password`. Type `/rms/admin/business-rules`.
4. Sign out. Sign in as `lucia@iconic.test` / `password`. Type `/rms/admin/business-rules`.
5. Still as Lucía, type `/rms/admin/permissions`.

## Expected
- [ ] E1 · CFO has no section switch and no **＋ New Reservation**.
- [ ] E2 · CFO at `/crm/sales/pipeline` is sent to `/rms/reservations/calendar`.
- [ ] E3 · Mateo at `/rms/admin/business-rules` is sent to `/rms/reservations/calendar` and a toast reads `You don't have permission to do that.`
- [ ] E4 · Lucía at `/rms/admin/business-rules` gets the same redirect and toast.
- [ ] E5 · Lucía at `/rms/admin/permissions` gets the same redirect and toast.
- [ ] E6 · Mateo and Lucía sidebars have no **Business Rules** or **Permissions** items.
