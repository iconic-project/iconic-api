# WEB-05 · Step 4 holds the cabin; leaving releases it
- **Tags:** sprint-8, web
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Web checkout holds take real inventory (K5). Abandoning must free the cabin or the calendar lies.

## Steps
1. **Guest.** Walk 2 adults, Nov 2027–Jan 2028, Western Realm, **7 Nov 2027 · ANAMARA**, `Select cabins — continue`.
2. On `/book/cabins` pick **Suite 03** (S01/S02 are `.taken`). Click `Next — your details`. This `POST /api/engine/checkout` holds the cabin.
3. **Carolina.** Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Read ANAMARA Suite 03 on `7 Nov 2027`.
4. **Guest.** Leave the flow: click `Back to trip details` (or `Back to cabins` then back to trip). Do not submit step 5. Confirm the guest is off `/book/details`.
5. Carolina: refresh Calendar Year 2027. Read the same cell.

## Expected
- [ ] E1 · After step 2, guest is on `/book/details`. Suite 01 and 02 stay unclickable.
- [ ] E2 · Calendar cell ANAMARA Suite 03 / 7 Nov is a hold: class `c-hold`, label `HOLD` (WEB hold, not `REQ` / `AGCY`). ⚠ UNVERIFIED — `calendarHelpers` WEB → `HOLD`.
- [ ] E3 · After the guest leaves, the cell is `·` (Available) again. ⚠ UNVERIFIED — DELETE checkout on back / pagehide.

## Notes
Do not wait 20 minutes — abandon must release now. If Back does not release, classify **BUG**. Guest context has no staff cookies. Target cabin is Suite 03 so this does not collide with seeded S01/S02. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
