# 18-05 — Quoter, price check and config checks on stays

**Repo:** iconic-api
**Depends on:** 18-02, 18-03, 18-04
**Read first:** `Services/Pricing/ReservationQuoter.php`, `ReservationQuote.php`, `BookingDiscounts.php`, `Services/Config/DepartureConfigChecks.php`, `RatesController::priceCheck`, `Http/Requests/Rms/*Quote*`, `Engine/QuoteController`

## Why
Callers never use the pricer directly. The quoter loads config, validates guests against the room type, applies discounts (online deposit, offers) and returns what the API shows.

## Build
1. `StayQuoter::quote(RoomType, StayQuoteInput, ?RatesDocument $draft = null): StayReservationQuote|NoRate|GuestsInvalid` — loads `CurrentConfig` rates/business rules/engine settings unless a draft is passed (price check on unpublished drafts, like today). Validates adults/children against the room type (`max_adults`, `max_children`, `max_occupancy`), child ages against engine settings. Applies `BookingDiscounts` (online deposit discount, `max_total_discount_pct`) on the stay total — port the logic, keep every existing rule, rename departure-based inputs to stay-based.
2. Multi-room: `quoteRooms(StayDates, list<room spec>)` returns per-room quotes plus a combined total; group rules (none today) stay out.
3. `POST /api/rms/bookings/quote` accepts the stay shape (`check_in`, `check_out`, rooms[]) when `check_in` is present; the departure shape keeps working through the adapter until Sprint 19.
4. `POST /api/rms/rates/price-check`: input = a draft rates document + list of stays → per-stay quotes. Default examples = fixture `reference_quotes`.
5. `DepartureConfigChecks` → `StayConfigChecks`: warn on nights in the sale horizon with no season, room types with no rate, restriction rows on nights with no rate. Exposed on the Rates page.

## Tests
Feature tests for both endpoints (happy, validation, roles); quoter guest validation matrix; discount cap still applied; draft price check never publishes.

## Done when
`price-check` reproduces every `reference_quotes` total from the fixture through HTTP.
