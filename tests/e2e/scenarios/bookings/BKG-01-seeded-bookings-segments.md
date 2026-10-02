# BKG-01 · Seeded bookings and segments
- **Tags:** sprint-4, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The list, segment chips and Groups panel must show the seed. A missed filter or a hidden REQUESTED row makes every later booking scenario look empty. Sprint 5 added a real Balance column and `ANK-2026-0021`.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Date range stays **All dates**.
2. Read the table and the date-range count. Click chips **D2C — direct**, then **B2B — travel trade**, then **Charter**, then **All**.
3. Scroll to the **Groups — multi-cabin reservations (OPS-008)** panel. Open GRP-007.

## Expected
- [ ] E1 · Date-range line `14 bookings · all dates`. Table includes ANK-2026-0003 … 0019, **ANK-2026-0021**, and ANK-R-2026-0041 / 0042.
- [ ] E2 · There is no Groups chip. Chips are `All` · `D2C — direct` · `B2B — travel trade` · `Charter`.
- [ ] E3 · **D2C — direct** hides ANK-2026-0007, ANK-2026-0012 and ANK-2026-0021. **B2B — travel trade** shows ANK-2026-0007 (M. Castellanos) and ANK-2026-0021 (Meridian Voyages hold). **Charter** shows only ANK-2026-0012 (Vandermeer Charter).
- [ ] E4 · Status pills use spaces: `PENDING PAYMENT` (0014), `FULLY PAID` (0005), `ON HOLD AGENCY` (0021), `REQUESTED` (0041 / 0042), `CONFIRMED` (the rest). 0018 is CONFIRMED with no OVERDUE pill after reset.
- [ ] E5 · Balance is `charges_total − settled` (cruise + extras + collected fees − paid), not the cruise-only remainder. 0003 `USD 23,940` (total `USD 26,600`). 0005 `USD 0`. 0007 `USD 21,267` (20,947 cruise + 320 HPRE). 0014 `USD 26,600`. 0021 `USD 26,600`. 0009 `USD 45,400` (45,000 cruise + 400 PNG collected). 0012 `USD 169,200`.
- [ ] E6 · Groups panel has GRP-007 `Alvear family & friends` with `coordinator Lorena Alvear`, `3 cabins · 6 guests`, Total `USD 79,800`, Balance `USD 71,820`, CONFIRMED.

## Notes
Values: `fixtures/reference-values.md` (Seeded bookings / Seeded money). Segment from `Booking::segment` + `ChannelSeedMap`. GRP-007 members are INBOUND → D2C. Next ANK after this seed is `ANK-2026-0022`.
