# BKG-04 · Festive charter

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
A festive week charter must take all nine cabins at the festive charter total. ANAMARA 19 Dec is already the seeded charter.

## Steps
1. Run `tests/e2e/bin/reset.sh`. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Confirm every ANATIVA cabin on `19 Dec 2027` shows `·` (Available). If any does not, **stop** — the reset did not apply.
2. Open `/rms/reservations/bookings`. `＋ New reservation`. Type **CHARTER (full yacht)**.
3. Guest `E2E Festive Charter`, email `e2e.bkg04@iconic.test`, phone `+1 555 0404`, preferred **EMAIL**. Main channel **D2C**, origin **Email**.
4. Departure `19 Dec 2027` · ANATIVA (the festive suffix is ` · FESTIVE (+supplement, discounts blocked)`). Adults may stay at the default. Cabin pickers stay hidden.
5. Read the price box and the charter notice. Payment method for deposit stays **Card — payment link**. `Create reservation`.
6. Success pane, then `Done` (toast fires on Done). Open Calendar Year 2027, ANATIVA 19 Dec.

## Expected
- [ ] E1 · After step 1 all nine ANATIVA cells on 19 Dec are `·`. Do not continue if they are not.
- [ ] E2 · Departure option includes ` · FESTIVE (+supplement, discounts blocked)`. ⚠ UNVERIFIED — `newReservationHelpers` festive suffix.
- [ ] E3 · Total `USD 211,500`. Deposit `USD 42,300` (20%). Notice includes `Deposit 20% within 5 business days of written confirmation; balance 80% at 120 days; DPNG manifest 30 days pre-departure.` Amounts from the fixture (Pest). Notice wording ⚠ UNVERIFIED — `charterNoticeText()` / task 08.
- [ ] E4 · After `Done`: toast `Reservation ANK-2026-0022 created.` ⚠ UNVERIFIED — next ref from Pest; toast from `createdToast()` on Done.
- [ ] E5 · Calendar Year 2027: all nine ANATIVA cabins on 19 Dec show `CHARTER`. ⚠ UNVERIFIED — task 10 state table.

## Notes
ANAMARA 19 Dec is ANK-2026-0012. This scenario uses **19 Dec 2027 ANATIVA** (DEP-014).
