# PORT-04 · This agency only, and no passenger detail
- **Tags:** sprint-13, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Ada Agent
- **Start:** reset

## Why
Bookings and commissions are scoped to the signed-in agency. The agent sees the lead guest's name and the net figures, not the passenger record. Another agency's reference is not a page on this site.

## Steps
1. Sign in as Ada at `http://localhost:3002/login`. Open `/bookings`.
2. Open `/commissions`.
3. Open `http://localhost:3002/bookings/ANK-2026-0021`.

## Expected
- [ ] E1 · Bookings lists only this agency. On a fresh hotel seed the list is empty. It does not list `ANK-2026-0021`.
- [ ] E2 · Commissions is the same scope. On a fresh hotel seed the list is empty. It does not list `ANK-2026-0021`.
- [ ] E3 · `/bookings/ANK-2026-0021` is the portal page titled `Page not found`.

## Notes
Hotel seed has no `ANK-2026-0007` and no `ANK-2026-0021`. The stay columns, the net, and the missing passport are HPOR-02. There is no per-booking route; the 404 is the portal page, not an API body. Hotel seed does not create `ada@portal.test`. If sign-in has no such user, stop and class ENV.
