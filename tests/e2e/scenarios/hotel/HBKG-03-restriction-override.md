# HBKG-03 · Restriction refusal and override with reason
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A stop-sell blocks the sale. Staff with permission can override it, and the reason is stored.

## Steps
1. Hotel seed. Sign in as Carolina. Open `http://localhost:3001/rms/inventory/restrictions`.
2. Set **From** and **To** to `2026-12-22`. Turn on **Standard Double**. **Stop sell** `Yes`. Reason `HBKG stop`. **Save restrictions**.
3. Open `/rms/reservations/bookings`. **＋ New reservation**. Check-in `2026-12-21`, check-out `2026-12-23`, Standard Double, Room 103, adults 2. Do not tick **Override**. Try **Create booking**.
4. Tick **Override**. Reason `HBKG override`. **Create booking**.

## Expected
- [ ] E1 · Without override, create is refused and the dialog shows the stop-sell on the restrictions list. No booking row appears.
- [ ] E2 · With the reason, the booking is created for Room 103, 21–23 Dec 2026.
- [ ] E3 · The booking history for the create includes the reason `HBKG override`.

## Notes
22 Dec 2026 is inside Peak, so the refusal is the restriction, not a missing rate. Room 103 is free that week in the seed (HTL-030 is not seeded; it has no season).
