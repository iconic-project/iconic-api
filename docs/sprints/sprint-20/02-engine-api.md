# 20-02 — Engine API: property, calendar, availability, quote

**Repo:** iconic-api
**Depends on:** 20-01
**Read first:** 09 H7, H8, H22; `Services/Engine/EngineFeed.php`, `EngineCabins.php`, `EngineFeedVersion.php`, `EnginePromoCheck.php`, `Support/Inventory/EngineLabel.php`, `Engine/FeedController`, `DepartureCabinController`, `QuoteController`, `docs/requirements/examples/booking-engine-feed.json`, rate limiters in `bootstrap/app.php`

## Why
The engine renders only what the RMS publishes (core rules). The published surface changes from "departures with cabins" to "nights with room types".

## Build
1. `GET /api/engine/property` — property + active, complete room types (content, occupancy, from-price over the next `calendar.horizon_months`), engine settings needed for rendering (copy, guests rules, locale), the **default** rate plan code and public plans. Cached with `EngineFeedVersion` (bumped by `AvailabilityChanged`, `ConfigPublished`, content updates). ETag support.
2. `GET /api/engine/calendar?from=YYYY-MM&months=1..3&adults&children` — per night: `available` (any room type bookable for 1 night with that party), `from_price` (lowest **nightly** total from `RoomPricer` for 1 night, default plan), `closed_to_arrival`, `closed_to_departure`, `min_stay`. Computed from `NightAvailability::countsByType` + `Restrictions` + one pricer call per (season, type) — not per night per type. Cached per (month, party) and invalidated by feed version.
3. `GET /api/engine/availability?check_in&check_out&adults&child_ages[]&rooms` — per room type: `bookable` + reasons (09 H22 list), `rooms_left` (capped at `availability.low_availability_threshold` + 1 so the engine can show "only n left" without exposing exact inventory), quotes per public plan (night lines, taxes, total, deposit). Multi-room: party distribution is the client's job; the API answers for "one room with this party" and "n rooms" counts.
4. `POST /api/engine/quote` — body 09 H22; returns the authoritative quote the checkout will charge, with a `quote_token` (signed: stay, rooms, plan, totals, rates version, expiry 15 min) so checkout can detect drift.
5. Promo check: `POST /api/engine/promo/check` takes the stay; offer logic stays departure-free per 09 H23 (full offer migration is 22-01 — here, validate promo against `check_in` as the travel date and mark `TODO(Sprint 22)`).
6. Old endpoints `GET feed` and `GET departures/{departure}/cabins` return **410 Gone** with a pointer to the new endpoints after the engine release (keep them working until 20-04 ships; then switch). Update `examples/booking-engine-feed.json` → new `examples/engine-property.json` and `examples/engine-availability.json`.
7. Behavioural events (`EngineEventsController`, `BehaviouralEventName`): add `search_performed` (stay, party), `room_type_viewed`; keep names from the existing enum where they still fit — do not invent if doc 07 lists names; check first.

## Tests
Each endpoint: shape snapshot, reasons, low-availability cap, cache invalidation on a new claim, rate limiting, no sensitive or internal data (no room numbers, no holder info). Performance: calendar for 3 months < 400 ms locally (record).

## Done when
The engine can render a full search → results flow from these four endpoints alone.
