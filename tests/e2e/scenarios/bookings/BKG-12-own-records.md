# BKG-12 · Own-records
- **Tags:** sprint-4, bookings
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
A Sales Exec may view others’ bookings but cannot transition them. The 🔒 must agree on the list, the panel and the Calendar.

## Steps
1. Sign in as `lucia@iconic.test` / `password` in a fresh context. Header `LUCÍA B. — SALES EXEC`. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Find Mateo’s ANK-2026-0005 (The Brandt Family) and ANK-2026-0016 (L. Alvear). Read the Owner cell. Open 0005. Read the header lock and Status transitions.
3. Open Lucía’s ANK-2026-0003. Confirm transitions are offered.
4. Open `/rms/reservations/calendar`. Date range **Year 2027**. Read ANAMARA Suite 02 on 7 Nov (0005, Mateo) and Suite 01 on 7 Nov (0003, Lucía). Open Booking Requests and read 0042 (Mateo) vs 0041 (Lucía).

## Expected
- [ ] E1 · List: Mateo’s owner cells append `🔒` (0005, 0009, 0016, 0017, 0019, 0042). Lucía’s do not (0003, 0007, 0011, 0014, 0018, 0041). Carolina’s charter 0012 has `🔒`. ⚠ UNVERIFIED — `bookings.ownedLock` when `can_act` is false.
- [ ] E2 · Panel on 0005: `🔒 OWNED BY MATEO R.` Transitions hidden or replaced by `You can view this booking but not modify it — own-records rule (Manager/Agent).` ⚠ UNVERIFIED — i18n `bookings.ownedBy` (name uppercased) / `bookings.ownRecords`.
- [ ] E3 · Panel on 0003: no lock banner. `→ FULLY PAID` and `→ CANCELLED` are offered.
- [ ] E4 · Calendar: Suite 02 7 Nov shows `🔒` (and `0005`). Suite 01 7 Nov has no lock (`0003`). ⚠ UNVERIFIED — task 10 `canActOnBooking`.
- [ ] E5 · Booking Requests: 0042 Confirm / Release disabled. 0041 Confirm / Release enabled. ⚠ UNVERIFIED — task 09 `can_act` on the queue.

## Notes
Lucía rights: `fixtures/accounts.md`. Lock is own-records, not a missing `bookings.view` permission.
