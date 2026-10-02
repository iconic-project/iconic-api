# PRIV-05 · Mateo has no subject-request panel
- **Tags:** sprint-10, crm
- **Priority:** P2
- **Users:** Mateo
- **Start:** reset

## Why
`privacy.manage` is Admin only. The register is not.

## Steps
1. Sign in as `mateo@iconic.test` / `password`. Open `http://localhost:3001/crm/system/consent`.
2. Read the register and the subject-request area.
3. `GET /api/privacy/requests` with Mateo’s session.

## Expected
- [ ] E1 · The register and data map load.
- [ ] E2 · The page says `Subject requests are handled by an Admin.` There is no **New request** button.
- [ ] E3 · `GET /api/privacy/requests` is 403.

## Notes
A subject-request list for Mateo is a **BUG**.
