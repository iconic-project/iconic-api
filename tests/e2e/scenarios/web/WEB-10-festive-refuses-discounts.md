# WEB-10 · A festive departure refuses every discount

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest
- **Start:** reset

## Why
Festive departures never take offers, the online advantage, or promo codes. A festive row that keeps `EARLY500` is a pricing leak.

## Steps
1. Guest. 2 adults, Nov 2027–Jan 2028. `Check availability`. Expand **Festive Expeditions**. Select a **visible** festive row — **not** 19 Dec 2027 ANAMARA (DEP-013, chartered / not shown). Use **19 Dec 2027 ANATIVA** or **26 Dec 2027** either yacht.
2. Continue to `/book/details` on a Suite.
3. Apply `EARLY500`. Read the promo message.
4. Select Option 2 and Option 1 in turn. Read the price panel. Confirm no OPENING-27 / ICONIC10 / online-advantage line.

## Expected
- [ ] E1 · Festive row shows `+ festive`. OPENING-27 / shoulder / early-booking badges are absent on festive cards and rows.
- [ ] E2 · `EARLY500` is removed. Server reason `This code does not apply to festive departures` and/or i18n `Your promo code does not apply to festive departures and was removed`.
- [ ] E3 · Quote lines are cabin + festive supplement only (Suite · 2 adults · festive **USD 28,100** before extras/fees). No `ICONIC10`, no `online_deposit`, no OPENING-27 credit. ⚠ UNVERIFIED — `CabinPricer` festive 28,100; panel may still show TCT/PNG info lines.
- [ ] E4 · Network `POST /api/engine/promo/check` `valid` is false; `reason` is the festive sentence.

## Notes
DEP-013 must not appear. Do not invent a festive discount. Guest context only. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
