# OFF-02 · Pause a live offer — gone from the engine within 30 seconds
- **Tags:** sprint-8, offers
- **Priority:** P2
- **Users:** Carolina + Guest
- **Start:** reset
- **Needs:** two browser contexts

## Why
Pausing must take the offer off the public feed (K4). Resume without a material edit returns LIVE.

## Steps
1. **Guest.** Confirm OPENING-27 is visible on a Nov 2027 WEST or NORTH card/row (badge `OPENING OFFER`) after a reset. If it is not, classify **BUG** and stop — do not skip the pause.
2. **Carolina.** `/rms/booking-engine/offers` (or Rates **Promotions**). On **OPENING-27** click `Pause`.
3. Guest: wait ≤ 30 s (or hide/show the tab). Reload itineraries / feed.
4. Carolina: `Resume` on OPENING-27.
5. Guest: wait ≤ 30 s. Confirm the badge returns.

## Expected
- [ ] E1 · Pause toast `Promotion paused — removed from the booking engine in < 30 seconds.` Row `PAUSED`.
- [ ] E2 · Within 30 s the guest feed and pages have no `OPENING-27` / `OPENING OFFER`.
- [ ] E3 · Resume toast `Offer resumed.` Status `LIVE`. Badge returns within 30 s. (If Carolina edited a price field while paused, resume is `PENDING DIRECTOR` — do not edit.)

## Notes
Rates **Promotions** compact list also has Pause on LIVE rows and `Manage in Offers →`. Either surface is valid. Before any other click on the engine, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
