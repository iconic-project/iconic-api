# GST-05 · Passport expiring before the return date
- **Tags:** sprint-6, guests
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A passport must be valid through check-out. Saving an expiry before check-out flags the guest and does not block the save.

## Steps
1. Sign in as Carolina. Open ANK-2026-0005. **Guests** tab.
2. **Edit** Julia Brandt. Set Passport expiry to `2027-11-01` (return is 14 Nov 2027). Do not change or copy the passport number. `Save guest`.
3. Read the issues warnbox.

## Expected
- [ ] E1 · Toast `Guest saved` — the save is not blocked.
- [ ] E2 · Warnbox includes `✕ Julia Brandt's passport expires before check-out (14 Nov 2027).`

## Notes
The stay checks out 14 Nov 2027. Never write Julia’s passport number into the report. Reference her by name.
