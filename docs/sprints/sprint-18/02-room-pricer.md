# 18-02 — `RoomPricer`

**Repo:** iconic-api
**Depends on:** 18-01
**Read first:** 09 H8; `Services/Pricing/CabinPricer.php`, `Quote.php`, `QuoteLine.php`, `QuoteInput.php`, `NoRate.php`, `Support/Rounding.php`, fixture `reference_quotes`

## Why
The heart of the hotel product. It must be pure, deterministic and explained line by line, like `CabinPricer`.

## Build
1. `App\Services\Pricing\StayQuoteInput`: `StayDates $stay`, `string $roomType`, `int $adults`, `list<int> $childAges`, `string $ratePlan` (code), `?string $promo` (unused until Sprint 22).
2. `App\Services\Pricing\RoomPricer::quote(RatesDocument, RoomType, StayQuoteInput): StayQuote|NoRate`. Pure — no DB, no config reads except the arguments.
3. Per night (09 H8 order), all integers, `Rounding::halfUp` at each percentage step:
   1. season = the season containing the night; none → `NoRate("No rate for {type} on {date}")`.
   2. `base = room_rates[type][season]`; missing → `NoRate`.
   3. occupancy: guests above `base_occupancy` → each extra adult `extra_adult_nightly`, each extra child `extra_child_nightly` (adults fill base first). Children are those with age within `engine_settings.guests.child_min_age … child_max_age`; younger children count per a `guests.infant_max_age` rule — **if not defined, refuse** (validation, not pricing).
   4. single occupancy (1 guest): `+ base × single_occupancy_pct / 100`.
   5. day of week: `× (100 + dow_pct) / 100` on the running night total.
   6. supplements covering the night: `per_night` (ROOM) or `per_night × guests` (PERSON).
   7. rate plan `adjust_pct` on the running night total.
4. Stay level: length-of-stay discount — highest band with `min_nights ≤ nights` — on the sum of nights, one line.
5. Output `StayQuote`:
   - `night_lines: list<{night, season, base, extras, single, dow, supplements, plan_adjust, total}>`,
   - `lines` (summary for documents): `room` (Σ base, label "Suite · 3 nights · Spring season"), one line per non-zero component, `length_of_stay`,
   - `total`, `deposit_pct`, `deposit` (from the rate plan, task 03; until then from `terms.cabin_deposit_pct`), `rates_version_id` passed through.
   Labels are i18n keys + parameters, rendered by a `QuoteLabels` helper — no English sentences built inside the pricer.
6. Guest-count limits are **not** the pricer's job (same as today): callers validate against the room type.

## Tests
- Every `reference_quotes` case from the fixture, exact lines and total.
- A stay crossing two seasons; a stay crossing a supplement range partially; LOS band boundaries (n−1, n, n+1); DOW on Friday/Saturday; single occupancy negative pct; rounding: three nights at a price that rounds differently per night vs on total (document that per-night wins).
- No rate on one night → `NoRate` naming the first missing night.
- Property-based test: total == Σ night totals − LOS discount, for 500 random stays from the fixture.

## Done when
`RoomPricer` has no `use` of `CabinCategory`, `Departure`, `CurrentConfig` or facades.
