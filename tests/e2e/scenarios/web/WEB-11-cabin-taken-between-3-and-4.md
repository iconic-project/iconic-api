# WEB-11 · Cabin taken in the RMS between steps 3 and 4

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
The engine cannot sell a cabin the RMS has taken. The 409 must name the cabin and send the guest back to pick again.

## Steps
1. **Guest.** 2 adults, Nov 2027–Jan 2028. Select **7 Nov 2027 · ANATIVA** (Northern Passage — all nine cabins free after reset). Stop on trip details (`/itineraries/northern-passage`). Do **not** open cabins yet.
2. **Carolina.** `/rms/reservations/bookings` → `＋ New reservation`. Type CABIN. Guest `E2E Web11`, email `e2e.web11@iconic.test`, phone `+1 555 0811`, preferred EMAIL, D2C, Hotel Booking Engine. Departure `7 Nov 2027` · ANATIVA. Adults 2. Cabin **Suite 01**. `Create reservation` → `Done`.
3. **Guest.** `Select cabins — continue`. Pick **Suite 01** if it is still clickable, then `Next — your details`. If Suite 01 is already `.taken`, pick it is impossible — record that and pick Suite 01’s taken state, then try Continue without a free pick if the UI allows, or pick Suite 01 only if the stale deck still offers it.

## Expected
- [ ] E1 · Carolina: toast `Reservation ANK-2026-0022 created.` (next ANK after reset). Suite 01 on 7 Nov ANATIVA is sold.
- [ ] E2 · Guest Continue / checkout 409 names **Suite 01** (API `unavailable[].cabin.label`). Picks for that cabin are cleared. Deck refreshes; Suite 01 is `.taken`. The guest can pick another cabin. ⚠ UNVERIFIED — task 09 `cabwarn` / 409 body.
- [ ] E3 · If the deck refreshed before Continue and Suite 01 is already `.taken`, that also passes the “engine names it” intent — the cabin is not selectable. Prefer the 409 path when the stale pick is still shown.

## Notes
Target is 7 Nov ANATIVA so this does not collide with ANAMARA seed claims. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
