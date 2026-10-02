# AUTH-01 · Return to the page you asked for
- **Tags:** sprint-1, auth
- **Priority:** P1
- **Users:** Mateo
- **Start:** reset

## Why
Deep links must survive sign-in. If `redirect` is dropped, staff lose the page they asked for.

## Steps
1. With no session (fresh context or signed out), open `http://localhost:3001/rms/reservations/bookings`.
2. On `/login`, sign in as `mateo@iconic.test` / `password`.

## Expected
- [ ] E1 · Step 1 lands on `/login?redirect=/rms/reservations/bookings` (not a bare `/login`).
- [ ] E2 · After sign-in the URL is `/rms/reservations/bookings`.
- [ ] E3 · The page title is `Bookings` (placeholder body `Coming in Sprint …` is fine).
- [ ] E4 · Header shows `MATEO R. — MANAGER`.
