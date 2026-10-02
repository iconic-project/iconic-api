# AUTH-06 · A disabled user is signed out on their next action
- **Tags:** sprint-1, auth
- **Priority:** P2
- **Users:** Carolina · Lucía
- **Start:** reset
- **Needs:** two browser contexts

## Why
A disabled account must not keep working until they refresh. The next API call must 401 and return them to login.

## Steps
1. Context A: sign in as `carolina@iconic.test` / `password`. Stay on `/rms/admin/permissions`.
2. Context B: sign in as `lucia@iconic.test` / `password`. Stay on `/rms/reservations/calendar`.
3. Context A: find Lucía B. Click `Disable`. Confirm (`They're signed out on their next action.`). Optional reason can be left empty. Click `Disable`.
4. Context B: navigate to `/rms/reservations/bookings` (any page that hits an authenticated API — not `/api/health`).
5. Context B: try to sign in again as `lucia@iconic.test` / `password`.

## Expected
- [ ] E1 · After disable, toast `User disabled`. Lucía’s pill is `Disabled`.
- [ ] E2 · Lucía’s next navigation lands on `/login` (may include `?redirect=/rms/reservations/bookings`).
- [ ] E3 · Signing in as Lucía shows `These credentials do not match our records.` (same as a wrong password).

## Notes
Public `GET /api/health` does not kick a disabled session.
Status pills are i18n sentence case (`Disabled`) and CSS-uppercase on screen (`DISABLED`). Match case-insensitively.
