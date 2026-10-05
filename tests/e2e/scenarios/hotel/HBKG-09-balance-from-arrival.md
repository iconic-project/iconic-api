# HBKG-09 · Balance due counts from arrival
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
The balance date is measured from check-in, using the rate plan's balance days. It is not a departure date.

## Steps
1. Hotel seed. Sign in as Carolina. Create a Best available Standard Double, check-in `2026-12-21`, check-out `2026-12-23`, Room 105, adults 2.
2. On the bookings list, read **Balance due** for that row.

## Expected
- [ ] E1 · Balance due is `2026-11-30`. Best available in the hotel fixture has `balance_days` 21, counted back from `2026-12-21`.
- [ ] E2 · The row has no departure date. The stay column is 21–23 Dec 2026.

## Notes
Do not use a yacht balance of 120 days. That rule is not this plan.
