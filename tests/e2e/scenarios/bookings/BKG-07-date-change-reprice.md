# BKG-07 · Date change reprices

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
A date change re-quotes at current rates. Staff confirm the festive delta. A group member cannot move to another departure (G2 / task 04).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0003. Click `Move to another departure…`.
2. Departure `19 Dec 2027` · ANATIVA (festive). Cabin any free Suite (Suite 01). Wait for the preview.
3. Read Current total, New total, Difference, festive note, FIN-006 line. `Confirm move`.
4. Open History.
5. Close the panel. Open ANK-2026-0016 (L. Alvear, GRP-007). `Move to another departure…`. Pick a **different** departure (any future ANATIVA Sunday). Confirm move (or the preview POST if the dialog errors before confirm).

## Expected
- [ ] E1 · Preview: Current total `USD 26,600`. New total `USD 28,100`. Festive changes is shown. `No modification fee (FIN-006).` ⚠ UNVERIFIED — i18n `bookings.noModFee` / `festiveChanges`; totals from the fixture (Pest: Suite 2 AD vs festive).
- [ ] E2 · Toast `Booking moved`. Overview departure is `19 Dec 2027` · ANATIVA. Total `USD 28,100`. ⚠ UNVERIFIED — i18n `bookings.movedToast`.
- [ ] E3 · History: `Moved · 7 Nov 2027 · Suite 01 → 19 Dec 2027 · Suite 01 · USD 26,600 → USD 28,100` (cabin label follows the one picked in step 2). ⚠ UNVERIFIED — i18n `history.events.bookingMoved`.
- [ ] E4 · ANK-2026-0016 move to another departure: `.warnbox` `This booking belongs to GRP-007 — moving a group to another departure isn't supported yet.` The booking stays on 28 Nov 2027 ANAMARA Suite 01. (API sentence; Pest.)

## Notes
Do not start the festive move on a GRP-007 member — that 409 is the **last** step. ANK-2026-0003 is not in a group.
