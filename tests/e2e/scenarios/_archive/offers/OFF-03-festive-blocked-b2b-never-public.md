# OFF-03 · Festive itinerary cannot be selected; B2B never shows publicly
- **Tags:** sprint-8, offers
- **Priority:** P2
- **Users:** Carolina + Guest
- **Start:** reset
- **Needs:** two browser contexts

## Why
Festive itineraries are never eligible. B2B offers are never public (K2 / K3).

## Steps
1. **Carolina.** `/rms/booking-engine/offers` → `＋ New offer`. The applies section is a stay window, min nights, room types, and rate plans. There is no itinerary list.
2. Leave the new-offer drawer (or close). On the list, find **VIRTUOSO-EARLY** (OF-002). Confirm channel B2B / placement `NOT PUBLIC`.
3. **Guest.** Search Nov 2027–Jan 2028. Read cards, rows, and `GET /api/engine/feed` JSON for `VIRTUOSO-EARLY`, `Virtuoso`, and extra partner-commission copy.

## Expected
- [ ] E1 · The new-offer drawer has no itinerary checklist and no festive tick. Stay window, min nights, room types, and rate plans are the scope.
- [ ] E2 · VIRTUOSO-EARLY is LIVE in the RMS and absent from the feed `offers[]` and every departure `offers[]`. Guest pages do not show that code or a Virtuoso commission badge.
- [ ] E3 · Seeded promo codes (`ICONIC10`, `ADVISOR5`, `EARLY500`) are also absent from the feed (same as WEB-02).

## Notes
Do not save a new offer unless needed to prove the festive tick is ignored — the disabled control is enough. Guest context has no staff cookies. Before any other click on the engine, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
