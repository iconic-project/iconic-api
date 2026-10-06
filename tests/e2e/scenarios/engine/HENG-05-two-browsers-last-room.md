# HENG-05 · Two browsers race for the last room
- **Tags:** sprint-20, engine
- **Priority:** P1
- **Batch:** B25
- **Users:** Carolina + two guests
- **Start:** reset
- **Needs:** three browser contexts

## Why
One Suite room is left. Two guests hold it at the same time. One hold lands. The other is refused.

## Steps
1. **Carolina.** Open `http://localhost:3001/rms/reservations/calendar`. Property **Hotel Demo**. Click `Next` until **21 Dec 2026** is on screen.
2. Block **Room 108** for check-in 21 Dec 2026, check-out 25 Dec 2026 (the HINV-02 drag, or New block with that stay). Reason `HENG-05`. Repeat for **Room 208**. Leave **Room 308** free.
3. **Guest A** and **Guest B**, separate contexts, no staff cookies. Each opens `http://localhost:3000`, clicks `Analytics off`, and searches 21–25 Dec 2026, 2 adults, 1 room.
4. Each selects **Suite**, plan **Best available**, `Continue`, then fills a name and `e2e.heng05a@iconic.test` or `e2e.heng05b@iconic.test`, ticks every declaration, and clicks `Hold these rooms`. Do the two clicks as close together as the pages allow.

## Expected
- [ ] E1 · Before either hold, Suite shows one room left (`Only 1 left`).
- [ ] E2 · One guest sees `Rooms held until the timer ends`.
- [ ] E3 · The other guest is refused. The message is `STE is unavailable on 2026-12-21.` No second hold exists for Suite on those nights.

## Notes
Three Suite rooms are in the fixture (108, 208, 308). None are claimed in December. Blocking two leaves one. The loser must not end on the confirmation page.
