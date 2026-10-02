# GST-01 · Guests tab on ANK-2026-0005
- **Tags:** sprint-6, guests
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The Brandt booking is the complete-party fixture: a lead, a minor with guardian consent, and derived PNG categories. If the tab invents fees or hides the minor, later extras and Contacts In are reading a lie.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Open ANK-2026-0005 (The Brandt Family). **Guests** tab.

## Expected
- [ ] E1 · KPI **Complete** `3/3` with subtitle `needed for DPNG list & manifest`. KPI **PNG fees (by nationality)** `USD 500` with subtitle `paid at SCY airport` (`png_collected` is false). ⚠ UNVERIFIED — task 07 browser / seed, not a cloud reset.
- [ ] E2 · Markus Brandt has pill `LEAD`. Julia Brandt has no MINOR pill. Leon Brandt has pill `MINOR`. Do **not** copy any passport number into the report; Carolina sees the **full** number on each card (not `••••`).
- [ ] E3 · Leon’s card shows `Guardian Markus Brandt ✓` and relationship Father is on the form when edited. Age on departure is `12 yrs on departure`. Nationality Germany.
- [ ] E4 · PNG lines: Markus and Julia `PNG: Foreign adult (>12) · USD 200`. Leon `PNG: Foreign minor (≤12) · USD 100`. Amounts from engine settings `fees.png.foreign_over_12` / `foreign_12_and_under`.
- [ ] E5 · `＋ Add guest` is hidden (`can_add` false at cabin max 3).

## Notes
Values: `fixtures/reference-values.md` (Fee table, Seeded guests). Leon’s `is_minor_now` is calendar age vs **today**, not departure. Reference guests by name only.
