# HOPS-01 · Confirmation document shows stay, times, taxes, plan terms
- **Tags:** sprint-21, hotel, documents
- **Priority:** P1
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
A confirmation describes the stay. Check-in and check-out times, taxes, and the rate plan's cancellation terms come from the issued snapshot, not from a departure.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Open **HTL-027**. **Documents** tab. On **Booking Confirmation & Invoice** click `Preview`.

## Expected
- [ ] E1 · The preview has a **Your stay** block. Property **Hotel Demo**. Check-in `2026-10-12 · 15:00`. Check-out `2026-10-17 · 11:00`. Room type **Standard Double**. Nights `5`.
- [ ] E2 · A **Taxes and fees** block is present. It lists collected lines or the sentence `No taxes or fees collected on this stay.`
- [ ] E3 · A **Payment schedule** block is present. It has a **Cancellation** row. The cancellation text is not empty.
- [ ] E4 · The preview does not use a departure date as the stay.

## Notes
HTL-027 is confirmed, room 101, five nights from 12 Oct 2026. Times are the seeded stay rules (`15:00` / `11:00`). Do not type a price.
