# PORT-07 · Staff and agent sessions stay on their own apps
- **Tags:** sprint-13, portal
- **Priority:** P2
- **Batch:** B17
- **Users:** Carolina + Ada Agent
- **Start:** reset

## Why
A staff session is not a portal session, and an agent session is not a staff session. One check in each direction is enough; do not walk every route.

## Steps
1. Context A: sign in as Carolina at `http://localhost:3001/login`. From that signed-in page, request `GET http://localhost:8000/api/portal/me` with credentials (the staff session cookie).
2. In the same context, open `http://localhost:3002/login`.
3. Context B (a separate browser context, no staff cookie): sign in as Ada at `http://localhost:3002/login`. Open `http://localhost:3001/rms/reservations/calendar`.
4. From Ada's signed-in portal page, request `GET http://localhost:8000/api/auth/me` with credentials (the portal session cookie only).

## Expected
- [ ] E1 · `GET /api/portal/me` with the staff session is 401. The message is `Unauthenticated.`
- [ ] E2 · The portal shows `Sign in`. It does not show Rates or `Signed in as` Carolina.
- [ ] E3 · The panel shows the staff login, not the calendar and not `CAROLINA M. — ADMIN`.
- [ ] E4 · `GET /api/auth/me` with only the portal session is 401.

## Notes
Cookies are per browser context. Do not sign both users into one context: localhost shares a cookie jar across ports. One portal route and one staff route are the check. A walk of every `/api/rms`, `/api/crm` and `/api/privacy` path is the Pest separation test, not this scenario.
