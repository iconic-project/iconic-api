# GST-03 · Fill an incomplete guest
- **Tags:** sprint-6, guests
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Guest data arrives piece by piece. Completeness and the list GUESTS line must move when a named slot becomes complete; the issues list must shrink when the API’s warnings go away.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0014 (R. Ellison). **Guests** tab. Read Complete and the cards.
2. **Edit** Robert Ellison. Fill Passport number with dummy `E2E001400`, Passport expiry `2032-06-01`, tick `Travel insurance declared (OPS-005 — passenger's sole responsibility)`. `Save guest`.
3. Read Complete, the issues warnbox, Overview party line, and the list `GUESTS` line after the panel returns to Overview.

## Expected
- [ ] E1 · Before save: Complete `0/2`. Robert is incomplete. Guest 2 reads `Guest 2 — name pending`. PNG subtitle includes `1 guest pending data` (empty slot). ⚠ UNVERIFIED — seed + task 07/08 browser.
- [ ] E2 · After save: toast `Guest saved`. Complete `1/2`. Robert’s card is no longer incomplete (`gcard-inc` gone) and shows `Insurance declared ✓`.
- [ ] E3 · Overview party line includes `1/2`. List under the client shows `GUESTS 1/2` (not `3/3`).

## Notes
0014 is PENDING PAYMENT, so insurance / missing-consent issues do not fire until CONFIRMED. Completeness is first + last + dob + nationality + passport + expiry + insurance. Dummy passport only — never a seeded number.
