# BKG-03 · Create a three-cabin group

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
Several cabins on one departure must create one GRP and one booking per cabin, with the lead guest as coordinator.

## Steps
1. Run `tests/e2e/bin/reset.sh`. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Confirm ANATIVA Suite 03, Suite 04 and Suite 05 on `21 Nov 2027` each show `·` (Available). If any does not, **stop** — the reset did not apply.
2. Open `/rms/reservations/bookings`. `＋ New reservation`. Type **CABIN (FIT / Group)**.
3. Lead guest `Mira Lead`, email `e2e.bkg03@iconic.test`, phone `+1 555 0303`, preferred **EMAIL**. Main channel **D2C**, origin **Email**.
4. Departure `21 Nov 2027` · ANATIVA. First cabin Suite 03, 2 adults. `＋ Add another cabin` → Suite 04, 2 adults. Add Suite 05, 2 adults. Group name `E2E three cabin`.
5. Read the OPS-008 notice and the group total. Payment method for deposit stays **Card — payment link**. `Create reservation`.
6. Success pane, then `Done` (toast fires on Done). Read the Groups panel and the three new rows.

## Expected
- [ ] E1 · After step 1 those three ANATIVA cells on 21 Nov are `·`. Do not continue if they are not.
- [ ] E2 · Group notice `Group booking (OPS-008): same rates and conditions as FIT. Each cabin gets its own booking ID under one group; the lead guest is the group coordinator and single point of contact.` ⚠ UNVERIFIED — i18n `bookings.ops008`.
- [ ] E3 · Group total `USD 79,800` (3 × 26,600). Each cabin line `USD 26,600`. ⚠ UNVERIFIED — composed from fixture Suite · 2 adults; not a reset screen.
- [ ] E4 · After `Done`: toast `3 cabins created under GRP-008 (ANK-2026-0022, ANK-2026-0023, ANK-2026-0024). The coordinator receives all communications.` ⚠ UNVERIFIED — `createdToast()` on Done + next refs from Pest (0021 is already seeded).
- [ ] E5 · Groups panel shows GRP-008 `E2E three cabin` with coordinator Mira Lead. Three `PENDING PAYMENT` rows. ⚠ UNVERIFIED — status label and coordinator wording.

## Notes
Target cabins: **21 Nov 2027 ANATIVA Suite 03–05** (DEP-006). ANATIVA has no seed bookings.
