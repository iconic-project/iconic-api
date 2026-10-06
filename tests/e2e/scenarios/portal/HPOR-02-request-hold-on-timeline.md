# HPOR-02 · Agency request, hold visible in the RMS
- **Tags:** sprint-20, portal
- **Priority:** P1
- **Batch:** B26
- **Users:** Ada Agent + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
A portal request on a free Family room holds that room. The RMS timeline shows the hold. The request is not cancelled.

## Steps
1. **Ada.** Sign in at `http://localhost:3002/login`. Open `/availability`. Stay 21–25 Dec 2026, adults `2`. Click `Show`, then `Request` on **Family**.
2. Room type Family, plan left as offered, adults `2`. Client name `E2E Guest`. Client email `e2e.hpor02@portal.test`. Tick `The client of record is the end guest.` Click `Send request`. Note the reference.
3. Open `/bookings` and the new row.
4. **Carolina.** Open `http://localhost:3001/rms/reservations/calendar`. Click `Next` until 21 Dec 2026 is on screen. Read **Family free** for 21–24 Dec 2026. Open the request in Booking Requests.

## Expected
- [ ] E1 · The portal says `This request holds the room.` The reference is shown.
- [ ] E2 · The portal booking row shows check-in 21 Dec 2026, check-out 25 Dec 2026, and Family. The drawer has the client name and no passport, no guest email, and no other guest.
- [ ] E3 · **Family free** is `4` on 21–24 Dec 2026 (five Family rooms, one held). Booking Requests shows the reference, source portal, status REQUESTED, stay 21–25 Dec 2026.

## Notes
Same Ada gap as HPOR-01: hotel seed does not create `ada@portal.test`. Class that ENV. Five Family rooms: 107, 207, 305, 306, 307. None are claimed in December.
