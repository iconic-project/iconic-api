# 18-01 — Rates document v2

**Repo:** iconic-api
**Depends on:** Sprint 17
**Read first:** 09 H8; `RatesDocument.php`, `RateYear.php`, `RateTerms.php`, `RateRules.php`, `ConfigPublisher`, `ConfigValidator`, `ConfigVerifier`

## Why
Rates are the most sensitive config document. Its new shape must be validated as strictly as the old one before any price is computed from it.

## Build
1. Add to `RatesDocument` (new section classes under `Support/Config/Documents/Rates/`):
   - `seasons: list<{code, name, from: Y-m-d, to: Y-m-d}>` — inclusive ranges; `rules()`: codes distinct; `from ≤ to`; **no overlaps** (custom rule `SeasonsDoNotOverlap`). Gaps allowed.
   - `room_rates: list<{room_type: code, season: code, nightly: int ≥ 1}>` — `rules()`: each `(room_type, season)` pair at most once; `room_type` must exist and be ACTIVE (`exists` against `room_types.code`); `season` must be in `seasons`.
   - `occupancy: {extra_adult_nightly ≥ 0, extra_child_nightly ≥ 0, single_occupancy_pct −100…100}`.
   - `day_of_week: {1..7 → int −100…100}` (ISO weekday; missing = 0).
   - `length_of_stay: list<{min_nights ≥ 2, discount_pct 0…100}>` — ascending `min_nights`, distinct.
   - `supplements: list<{code, label, from, to, per_night ≥ 0, basis: ROOM|PERSON}>`.
   - `rate_plans`: shape only here (task 03 adds the behaviour) — `list<{code, name, default: bool, adjust_pct −100…100, refundable: bool, deposit_pct 0…100, balance_days 0…365, cancellation: band-set code, meal_plan: RO|BB|HB|FB}>`; exactly one `default`. Meal plan codes are industry abbreviations; confirm labels with the client and note `TODO(OPEN: …)` if 09 does not list them.
   - `schema_version: 2`.
2. `warnings()` (soft): a room type with no rate in some season; nights in the next `calendar.horizon_months` covered by no season; a supplement outside every season.
3. `labels()` for every path (the panel change list).
4. Config migration: publish a version that keeps `years/terms/rules` exactly and adds the v2 keys **from the hotel fixture** in testing/local, and **empty lists** elsewhere with `schema_version: 2` (production must enter real rates — empty seasons means every night is `NoRate`, which is safe). `approval_reference` `Sprint 18: rates v2 added (09 H8)`.
5. `iconic:config-verify` validates v2.
6. Mark `years`, `rules.back_to_back_pct`, `rules.festive_*`, `rules.single_supplement_pct`, `rules.triple_discount_pct`, `rules.child_discount*` as **legacy** in `labels()` ("Legacy (yacht)") and make them read-only in validation of new publishes (must equal the previous version's values).

## Tests
Validation matrix for every rule above (one failing case each); overlap detection edge cases (touching ranges `to = D`, `from = D+1` allowed; `to = from` of next = overlap); config-verify on fresh and migrated databases.

## Done when
A publish with overlapping seasons is refused naming both season codes.
