# HOPS-05 · Dashboard occupancy, ADR, and RevPAR
- **Tags:** sprint-21, hotel, reports
- **Priority:** P1
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
The commercial cards are hotel occupancy, ADR, and RevPAR for this month, the next 30 nights, and the next 90 nights, against the same range last year.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/dashboard`.
2. Read the first three KPI cards and the **Hotel occupancy** table.

## Expected
- [ ] E1 · The first three cards are **Occupancy**, **ADR**, and **RevPAR**. Occupancy is a one-decimal percent or `—`. ADR and RevPAR are whole USD or `—`. None of those three cards is **RevPAB**.
- [ ] E2 · The **Hotel occupancy** table has rows **This month**, **Next 30 nights**, and **Next 90 nights**.
- [ ] E3 · Each row has on-the-books Occupancy, ADR, and RevPAR, and the same three figures under **Same time last year** (a figure or `—`).
- [ ] E4 · Do not copy a percent from the screen into `fixtures/reference-values.md`. The numbers move with today.

## Notes
A departure occupancy table may still sit lower on the page. It is not these three cards.
