# PREQ-01 · A free Family room for four nights becomes a REQUESTED booking
- **Tags:** sprint-13, sprint-20, portal
- **Priority:** P1
- **Batch:** B17
- **Users:** Ada Agent + Lucía
- **Start:** reset

## Why
A portal request on a free room type creates a REQUESTED booking for that agency, freezes the agency commission, and holds the room for the stay. The team's Booking Requests screen shows where it came from.

## Steps
1. Sign in as Ada. Open `http://localhost:3002/availability`.
2. Choose check-in **21 Dec 2026** and check-out **25 Dec 2026** (four nights). Adults `2`. Click `Show`.
3. On **Family**, confirm a net price and `Request`. Open it.
4. Leave the room type `Family`, the plan, and 2 adults. Client name `E2E Guest`. Client email `e2e-preq01@portal.test`. Tick `The client of record is the end guest.` Click `Send request`.
5. As Lucía, open `http://localhost:3001/rms/reservations/booking-requests` and find the new reference. Open that booking and read the agency, the stay, and the frozen commission.

## Expected
- [ ] E1 · Family is bookable for those four nights and offers `Request`. The price is the agency net, not the public nightly.
- [ ] E2 · After send, the portal shows the new reference and `This request holds the room. The team will answer within 24 hours.` ⚠ UNVERIFIED — 24 is `sla.response_hours` on a fresh seed.
- [ ] E3 · Booking Requests shows it as `Portal · Blue Latitude Travel`, status REQUESTED. The booking's agency is Blue Latitude Travel, the frozen commission is 10%, check-in is 21 Dec 2026 and check-out is 25 Dec 2026.
- [ ] E4 · The Family room for those nights is held.

## Notes
The yacht departure list is retired. This stay is inside the hotel Peak season (20–31 Dec 2026). If that Family stay is already taken, use another four-night range in a published season where Family is bookable, and read those dates instead.
