# AUTH-07 · Redirects stay inside the panel
- **Tags:** sprint-1, auth
- **Priority:** P2
- **Users:** Mateo
- **Start:** reset

## Why
Open-redirect on `?redirect=` would send a staff session off-site.

## Steps
For each URL, use a signed-out context, then sign in as `mateo@iconic.test` / `password`.

1. Open `http://localhost:3001/login?redirect=//evil.com`.
2. Sign out. Open `http://localhost:3001/login?redirect=/\evil.com`. Sign in as Mateo.
3. Sign out. Open `http://localhost:3001/login?redirect=https://evil.com`. Sign in as Mateo.

## Expected
- [ ] E1 · After sign-in from `//evil.com`, the origin stays `http://localhost:3001` and the path is `/rms/reservations/calendar`.
- [ ] E2 · Same for `/\evil.com`.
- [ ] E3 · Same for `https://evil.com`.
- [ ] E4 · The browser location is never `evil.com`.
