# 22-01 — Offers and promos on stay windows

**Repo:** iconic-api, iconic-panel
**Depends on:** Sprints 19, 20
**Read first:** 09 H23; `Models/Offer.php` (travel-date logic ~L320–370), `Actions/Offers/*`, `Enums/Offer*.php`, `Services/Engine/EnginePromoCheck.php`, `Services/Pricing/BookingDiscounts.php`, panel `rms/booking-engine/offers.vue`

## Build
1. Migration: `offers` + `stay_from`, `stay_to` (inclusive nights), `min_nights` (nullable), `applies_to_room_types` (json, nullable = all), `applies_to_rate_plans` (json, nullable = all). Backfill: offers scoped to departures → `stay_from/to` = the union of those departures' stays; departure scoping columns nullable, dropped in 22-03.
2. Application rule: an offer discount applies **per night** that falls in `[stay_from, stay_to]`; stay must satisfy `min_nights`; booking date in the booking window (existing). Discount appears as a summary line and inside each eligible night line (frozen at sale).
3. `BookingDiscounts`: combined cap `max_total_discount_pct` still applies on the stay total.
4. Engine promo check uses the stay (removes 20-02's TODO). Engine labels (offer pills) per room type and per night in the calendar's `from_price`.
5. Panel offers editor: stay window with `AnkStayInput`-style range, min nights, room types, plans. Approval flow unchanged.

## Tests
Partial overlap (2 of 4 nights discounted), min nights, room type scoping, cap, engine promo check, backfill.

## Done when
No offer code references `Departure`.
