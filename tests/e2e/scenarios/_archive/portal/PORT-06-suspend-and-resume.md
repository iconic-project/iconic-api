# PORT-06 · Suspend ends the session; resume restores sign-in
- **Tags:** sprint-13, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Carolina + Ada Agent
- **Start:** reset

## Why
Suspending portal access signs the agent out and refuses the next sign-in with the same sentence as a wrong password. Bookings and commission are unchanged. Resume lets the same user back in.

## Steps
1. Sign in as Ada at `http://localhost:3002/login`. Leave that context on `/rates`.
2. In another context, sign in as Carolina. Open Blue Latitude Travel. Read the revenue line under the agency facts (`USD 23,275 · 1 · USD 2,328`) and **Portal access**.
3. Click `Suspend`. Reason `E2E suspend portal`. Confirm.
4. In Ada's context, open **Bookings** (or any other portal nav item).
5. On the portal login form, sign in as `ada@portal.test` / `password`.
6. As Carolina, read the revenue line again. Click `Resume`. Reason `E2E portal resume`. Confirm.
7. As Ada, sign in again with `password`.

## Expected
- [ ] E1 · Before suspend, portal access is `Open` and the button is `Suspend`. The note says suspending stops sign-in and ends live sessions, and that bookings and commissions are unchanged.
- [ ] E2 · Suspend title is `Suspend portal access`. Toast `Portal access suspended`. The drawer shows `Suspended`, Carolina's name, the time and the reason `E2E suspend portal`.
- [ ] E3 · Ada's next navigation lands on `/login` with `Please sign in again.`
- [ ] E4 · The sign-in in step 5 shows `These credentials do not match our records.` It does not say the agency is suspended.
- [ ] E5 · The revenue line is still `USD 23,275 · 1 · USD 2,328`.
- [ ] E6 · Resume toast is `Portal access resumed`. Access is `Open`.
- [ ] E7 · Ada's sign-in lands on `/rates`.

## Notes
Enforcement is the next portal request, not a background sweep. Do not change the agency's commission or bookings in this scenario.
