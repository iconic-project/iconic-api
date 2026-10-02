# ENG-05 · Engine settings read-only
- **Tags:** sprint-2, config
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
Sales Exec has neither `engine_settings.manage` nor `engine_copy.manage`.

## Steps
1. Sign in as `lucia@iconic.test` / `password`. Open `/rms/booking-engine/settings`.

## Expected
- [ ] E1 · State line is `VIEW ONLY — SALES EXEC` (role name from the signed-in user, uppercased).
- [ ] E2 · Every input is disabled. No approval field.
