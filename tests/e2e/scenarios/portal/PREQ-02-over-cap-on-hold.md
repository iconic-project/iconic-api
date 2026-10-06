# PREQ-02 · An over-cap agency's stay request is held
- **Tags:** sprint-13, sprint-20, portal
- **Priority:** P1
- **Batch:** B17
- **Users:** a Meridian portal user + Carolina
- **Start:** reset
- **Needs:** Mailpit, Horizon

## Why
A stay request from an agency above the commission cap is ON_HOLD_AGENCY. It raises the existing cap alert and task, and it does not sit on the REQUESTED queue. The room is still held.

## Steps
1. Run `tests/e2e/bin/setup.sh agency-over-cap AG-002`. Meridian is seeded at 15%, above the 12% cap, so this should report `changed` false.
2. Run `tests/e2e/bin/setup.sh portal-user AG-002`. Sign in on `http://localhost:3002/login` with the printed email and `password`.
3. Open Availability. Choose check-in **21 Dec 2026** and check-out **25 Dec 2026**. Adults `2`. Click `Show`. On **Family**, click `Request`.
4. Client `E2E Meridian Guest`, email `e2e-preq02@portal.test`. Tick `The client of record is the end guest.` Send.
5. As Carolina, find the new reference on `http://localhost:3001/rms/reservations/bookings` (All dates). Open `http://localhost:3001/rms/operations/alerts` and `http://localhost:3001/crm/sales/tasks` (kind **Commission cap**). Open `http://localhost:3001/rms/reservations/booking-requests`.

## Expected
- [ ] E1 · `agency-over-cap` leaves Meridian's commission above the cap (`changed` false, commission 15, cap 12).
- [ ] E2 · The portal confirmation includes `This request holds the room. The team will answer within 24 hours. This request is waiting on the commission-cap decision.` Status on the portal is `ON HOLD AGENCY`.
- [ ] E3 · The bookings list shows it `ON HOLD AGENCY` for Meridian Voyages. Alerts shows `Commission above cap (FIN-005)` for that reference. Tasks shows one **Commission cap** card for it with `commissions.override_cap`.
- [ ] E4 · Booking Requests does not list that reference as REQUESTED.
- [ ] E5 · The Family room for 21–25 Dec 2026 is held.

## Notes
Do not approve the cap. Hotel seed has no `ANK-2026-0021`, so this run's reference is the only cap task to read. The yacht departure calendar is retired. `setup.sh portal-user AG-002` needs that agency. Hotel seed does not create agencies. If the command cannot find AG-002, stop and class ENV.
