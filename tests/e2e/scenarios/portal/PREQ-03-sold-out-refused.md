# PREQ-03 · A stay that cannot be requested does not create a booking
- **Tags:** sprint-13, sprint-20, portal
- **Priority:** P1
- **Batch:** B17
- **Users:** Ada Agent
- **Start:** reset

## Why
A room type with no free room for the stay is refused. The form does not offer Request for that type, and sending it does not create a booking.

## Steps
1. Sign in as Ada. Open `http://localhost:3002/availability`.
2. Choose a stay where a room type shows a reason and no `Request` link (a sold-out type, or a stay with no rate).
3. Open `/requests/new` with that room type and those dates filled in the query. Client `E2E Guest`, email `e2e-preq03@portal.test`. Tick `The client of record is the end guest.` Send.

## Expected
- [ ] E1 · A room type that is not bookable has no `Request` link. Its reason is the text the API returned.
- [ ] E2 · Send shows the API refusal. No new reference is created.
- [ ] E3 · Availability for that stay is unchanged.

## Notes
The departure labels `FULL`, `FULL · WAITLIST` and `CHARTERED — NOT SHOWN` are retired. Do not invent a sold-out row; use one the search actually returns.
