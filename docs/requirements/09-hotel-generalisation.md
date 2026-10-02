# 09 — Hotel generalisation: decisions and target model

**Status:** adopted for sprints 16–22.
**Authority:** for anything about stays, rooms, nights, rates per night, check-in/out and the retirement of departures, this document **ranks above `08-dev-decisions.md`**. Everywhere else, `08` keeps its place. When this document says a yacht rule is retired, the yacht rule in `01`–`07` no longer applies, even if those documents are not edited.

Decisions are numbered **H1…H24** so tasks and code can cite them (`// see 09 H4`). Open items are **HQ1…HQ12**; use `// TODO(OPEN: HQn)` exactly like the existing `OPEN` markers.

---

## 1. Why

Iconic was built for 7-night Sunday→Sunday yacht cruises. Every sale is "a cabin on a departure". A hotel sells **a room for a range of nights that starts on any day**. The departure stops meaning anything: there is no fixed start date, no fixed length, no "return date", and inventory is consumed night by night.

Everything else that makes Iconic valuable — single write path, Actions, append-only history, config documents, frozen-at-sale prices, holds, request queue, payments, Stripe, agencies, CRM — stays. The migration changes **the unit of inventory and the clock every rule is measured against**, not the architecture.

## 2. Glossary (old → new)

| Yacht term | Hotel term | Code / table | Notes |
|---|---|---|---|
| Yacht | Property | `Property`, `properties` | Renamed table. A deployment may hold several properties. |
| Cabin | Room | `Room`, `rooms` | Renamed table. A physical, numbered room. |
| Cabin category (`SUITE`, `OWNER` enum) | Room type | `RoomType`, `room_types` | **Data, not an enum** (H6). |
| Itinerary | Property content + room type content | `properties` / `room_types` content columns | Marketing CMS moves (H20). |
| Departure | — (retired) | — | Replaced by the stay (H1, H2). |
| Departure date | Arrival date (`check_in`) | `bookings.check_in` | |
| Return date | Departure date of the guest (`check_out`) | `bookings.check_out` | "Departure" now only means **the guest leaving**. Never use it for inventory. |
| Cabin claim | Room-night claim | `RoomNightClaim`, `room_night_claims` | One row per room per night (H4). |
| Departure status `ON_SALE/CLOSED/HIDDEN` | Sell restrictions per date | `stay_restrictions` | H7 |
| Festive departure / festive supplement | Date supplement | rates document `supplements` | H8 |
| Back-to-back discount | Length-of-stay discount | rates document `length_of_stay` | H8 |
| ppdo (per person, per departure) | per room, per night (+ occupancy) | rates document | H8 |
| `ON_BOARD` | `IN_HOUSE` | `BookingStatus::InHouse` | H11 |
| `COMPLETED` | `CHECKED_OUT` | `BookingStatus::CheckedOut` | H11 |
| — | `NO_SHOW` | `BookingStatus::NoShow` | H11 |
| PNG / TCT fees | Taxes and fees | business rules `taxes` | H9 |
| DPNG / Captain manifests | Front-desk lists + guest registration export | `FrontDesk*` | H15 |
| Charter (whole yacht) | Exclusive use (buyout) | — | HQ1, H14 |
| Hotel manager brief (per departure) | Daily arrivals brief | `ArrivalsBrief` | H15 |

**Naming rule for code:** after Sprint 22, the identifiers `departure` (as inventory), `yacht`, `cabin`, `itinerary`, `voyage`, `cruise`, `png`, `ppdo` must not appear in `app/` except in `database/migrations` that already exist. An Arch test enforces this (Sprint 22).

## 3. Decisions

### Stay and night

**H1 — The room-night is the unit of inventory.** A *night* is a calendar date `D` meaning "the night from `D` into `D+1`". A stay `[check_in, check_out)` occupies nights `check_in … check_out − 1`. `nights = check_out − check_in`, always ≥ 1. Day-use (0 nights) is out of scope (HQ2).

**H2 — Any arrival day, any length.** No weekday rule. Length is limited only by `stay_restrictions` (min/max stay, closed to arrival/departure) and by the default `stay.min_nights` / `stay.max_nights` in business rules. `App\Support\Stays\StayDates` is the only type that carries a stay: it validates `check_in < check_out`, exposes `nights()`, `eachNight()`, `overlaps()`, `contains(night)`, and serialises `check_in` / `check_out` as `YYYY-MM-DD` via `CalendarDate`.

**H3 — Dates are sold, times are operational.** The booking stores `check_in` and `check_out` **dates**. Times are not inventory:
- Standard times live in business rules: `stay.check_in_time`, `stay.check_out_time` (`HH:MM`, property local time). Seed values are demo values (HQ3).
- The front desk records the **actual** moment: `bookings.checked_in_at`, `bookings.checked_out_at` (UTC timestamps, `Iso::utc()`). Check-in may happen **at any time** on or after the arrival date (H11). There is no slot booking.
- `bookings.expected_arrival_time` (`HH:MM`, nullable) is a guest-supplied hint for operations only.
- Early check-in before the standard time on the arrival day is allowed and free unless the property sells it as an extra. A guest who must be in the room the evening before books the previous night.
- Late check-out beyond `stay.check_out_time` is an **extra** (extras document), never a hidden extension of the night.

### Inventory

**H4 — One claim row per room per night.** `room_night_claims` replaces `cabin_claims`. Columns mirror `cabin_claims` (morph `holder`, `kind`, `hold_type`, `expires_at`, `released_at`, `release_reason`, audit) plus `room_id`, `night` (date). A stored generated column `active_key = if(released_at is null, concat(room_id,'-',night), null)` is `UNIQUE` — the database refuses double-selling a room-night exactly as it refused double-selling a cabin. The delete-prevention trigger is kept.

**H5 — A booking holds one room for its whole stay.** The `RoomAllocator` picks a specific room of the requested type that is free on **every** night of the stay. Staff can move the booking to another room later (Move). Split-room stays (room A nights 1–2, room B night 3) are out of scope (HQ4). Allocation rule v1: among rooms free for all nights, pick the one that **minimises fragmentation** (prefer a room whose night before `check_in` or night `check_out` is already occupied), then lowest `sort`, then lowest `id`. Deterministic, unit-tested.

**H5b — Locking.** `ClaimService` locks the candidate `rooms` rows `FOR UPDATE` in ascending `id` order (replaces `DepartureLocks::lock`). The unique `active_key` is the final guard; a unique violation becomes `RoomUnavailableException` → HTTP 409, same contract as `CabinUnavailableException` today. Holds expire exactly as today (`HoldExpired` stays the in-transaction exception from `laravel.mdc`).

**H6 — Room types are data.** `room_types`: `property_id, code (unique per property), name, base_occupancy, max_occupancy, max_adults, max_children, sort, status (ACTIVE/INACTIVE)` + content (H20). `rooms.room_type_id` replaces `cabins.category`. Guest-count checks (`engine_settings.guests.max_per_cabin`) move to the room type. `CabinCategory` is deleted in Sprint 22.

**H7 — Sell controls are per-date data.** `stay_restrictions` rows: `property_id, room_type_id (nullable = all types), night, stop_sell (bool), closed_to_arrival (bool), closed_to_departure (bool), min_stay (nullable), max_stay (nullable), note (nullable)`. Written only by `SetStayRestrictions` (bulk date-range Action, one history entry per call with the range). Precedence: room-type row beats property-wide row beats business-rule default. Min-stay is evaluated **on the arrival night** (industry "min stay through" is HQ5). What happens to departure fields:

| Departure field | Becomes |
|---|---|
| `status` ON_SALE / CLOSED / HIDDEN | `stop_sell` on the date range (hidden-from-engine vs closed-for-staff is HQ6; v1: stop_sell blocks engine and portal, staff can still book with `bookings.override_restrictions` permission) |
| `urgency_threshold` | engine settings `availability.low_availability_threshold` |
| `waitlist_enabled` | room type `waitlist_enabled` |
| `festive` | rates `supplements` (H8) |
| `public_note` | dropped (HQ6) |

### Money

**H8 — Rates are nightly.** The rates document becomes (shape version 2):
- `seasons`: named date ranges `{code, name, from, to}` (inclusive, non-overlapping, gaps allowed; a night with no season has **no rate** → `NoRate`, not zero).
- `room_rates`: `{room_type, season, nightly}` — price of the room for `base_occupancy` guests.
- `occupancy`: `extra_adult_nightly`, `extra_child_nightly`, `single_occupancy_pct` (signed whole percent, e.g. `-10`).
- `day_of_week`: optional whole-percent adjustment per ISO weekday (default all 0).
- `length_of_stay`: bands `{min_nights, discount_pct}` — replaces back-to-back.
- `supplements`: `{code, label, from, to, per_night, basis: ROOM|PERSON}` — replaces festive.
- `rate_plans`: `{code, name, adjust_pct, refundable, deposit_pct, balance_days, cancellation (band set code), meal_plan}`. Exactly one plan is `default`.
- `terms`: kept for anything not on a plan.

`RoomPricer` (replaces `CabinPricer`) prices **each night** to an integer USD (half-up, `Rounding::halfUp`), then sums. Order per night: room nightly → occupancy extras → single occupancy → day-of-week → supplement → rate-plan adjust. Length-of-stay discount applies once on the stay subtotal. The booking stores `price_lines` (summary lines as today) **plus** `night_lines` (one row per night: `night, season, base, extras, adjustments, total`). Both are frozen at sale (core rule 7).

**H9 — Taxes and fees replace PNG/TCT.** Business rules `taxes`: list of `{code, label, basis: PER_STAY|PER_NIGHT|PER_PERSON_PER_NIGHT|PCT_OF_ROOM, amount_or_pct, child_exempt_under_age, charged: bool, shown_in_price_panel: bool}`. `charged=false` reproduces today's "informational, paid locally" behaviour. `PngCategory`, `ApplyPng`, `bookings.png_collected` and `engine_settings.fees.png/tct_pp` are retired.

**H10 — The clock is the stay.** Every "days before departure" becomes "days before `check_in`"; every "after the cruise / return" becomes "after `check_out`":

| Rule (business rules key) | Measured from |
|---|---|
| balance due (`rate_plan.balance_days` / `terms`) | `check_in` |
| cancellation bands | `check_in` |
| hold rule near-term vs long-lead (`holds.near_term_max_days`) | `check_in` |
| `documents.pretrip_days_before` → `documents.pre_arrival_days_before` | `check_in` |
| `documents.voucher_days_before` | `check_in` |
| `commission.payable_days_after_cruise` → `payable_days_after_check_out` | `check_out` |
| `nps.survey_hours_after_return` → `survey_hours_after_check_out` | `checked_out_at` if set, else `check_out` at `stay.check_out_time` |
| `retention.*_after_cruise` → `*_after_check_out` | `check_out` |
| journeys clock "departure" anchor → `arrival` / `check_out` anchors | stay |
| `alerts.low_occupancy_*` | rolling window of nights (H15) |

Renamed keys ship with a config migration through `ConfigPublisher` (see `laravel.mdc`, Configuration documents), same values, `approval_reference` `Sprint N: <key> renamed (09 H10)`.

### Booking lifecycle

**H11 — Status machine.** `ON_BOARD` becomes `IN_HOUSE`, `COMPLETED` becomes `CHECKED_OUT` (enum values change; a data migration rewrites existing rows and history `what` stays untouched — history is append-only). New terminal status `NO_SHOW`.
- `→ IN_HOUSE` only through `CheckInBooking` (front desk). Date guard: `today (property tz) ≥ check_in`. Payment guard: from `FULLY_PAID`, or from `CONFIRMED` when business rule `stay.check_in_requires_full_payment = false`. Records `checked_in_at` and the room actually given (may differ from the allocated room → implicit Move, one history entry).
- `→ CHECKED_OUT` only through `CheckOutBooking`. Allowed any time while in house (early departure is H12). Records `checked_out_at`.
- `→ NO_SHOW` only through `MarkNoShow`, reason mandatory, allowed from `CONFIRMED`/`FULLY_PAID` when `today > check_in` (or `today = check_in` after `stay.no_show_cutoff_time`). Releases nights from `check_in + 1` onward unless the no-show policy keeps them (HQ7). The no-show charge is computed from the rate plan's cancellation set, band "0 days".
- **No automation changes a stay status** (extends core rule 9). `VoyageStatus` becomes `NightAudit`: it raises alerts/tasks for arrivals not checked in, departures not checked out and in-house guests past `check_out`, and nothing else.

**H12 — Stays can change.** `ModifyStay` (new Action) handles extend, shorten, change dates and change room type. Rules:
- New nights are claimed first; if any night is unavailable the whole change fails (409) — no partial changes.
- Frozen at sale holds: original `night_lines` are never repriced. **Added** nights are priced at the **current** rates version and appended with their own `rates_version_id` on the line. **Removed** nights are credited at the price they were sold at; whether a penalty applies follows the cancellation bands (HQ8).
- Changing only the room (same dates) is the existing Move, generalised to nights.
- `modification_fee_usd` keeps its meaning.

**H13 — Groups.** A group (`GRP-…`) ties several bookings with one payer/contact. Bookings in a group **may have different dates and room types**. "Create a three-room group" creates three bookings in one Action and one transaction, as today.

**H14 — Exclusive use.** The yacht charter generalises to booking **every active room of a property** for a date range under one booking of type `BUYOUT`. Whether the client wants it at all is **HQ1**. Until answered, Sprint 22 retires the charter flow behind a feature flag in engine settings (`buyout.enabled = false`) rather than deleting it.

### Operations

**H15 — Front desk replaces manifests.** DPNG and Captain manifests are retired. The panel gets **Arrivals**, **In house** and **Departures** lists for any date, and a **guest registration export** (CSV/PDF) whose column set is configurable in business rules (`registration.fields`) because it depends on jurisdiction (HQ9). `HotelManagerBrief` per departure becomes `ArrivalsBrief` per date. Low-occupancy alert becomes "occupancy for the next `alerts.low_occupancy_days_before` days below `low_occupancy_pct` on N consecutive nights".

**H16 — Guest data.** Passport number, nationality and date of birth stay (registration needs them in many countries) and stay encrypted/sensitive exactly as today. Medical/dietary/accessibility notes stay. `PngCategory` is removed from guests.

### Platform

**H17 — Currency.** USD whole dollars stay (core rule 4). Multi-currency is **HQ10** and not part of this migration.

**H18 — Time zone.** One business time zone for the deployment (`BusinessTime`, as today). "Today" for every date guard is the property's local date. Multiple properties in different time zones is **HQ11**.

**H19 — Migration strategy: expand → migrate → contract.**
1. *Expand* (16–19): add the new tables/columns next to the old ones; backfill from yacht data (a departure on `D` with an itinerary of `n` nights becomes `check_in = D`, `check_out = D + n`; each active cabin claim becomes `n` room-night claims).
2. *Migrate* (17–21): switch each domain's code and tests to the new model, one domain per task, keeping `composer check` green after every task.
3. *Contract* (22): drop `departures`, `itineraries`, `cabin_claims`, `bookings.departure_id`, retired enums and config keys.
Never edit a merged migration (`laravel.mdc`). Every rename is a new migration.

**H20 — Content.** The itinerary CMS (hero image, tagline, highlights, chips, facts, FAQs, included/excluded, SEO fields, slug) splits into property content (`properties`: name, address, description, hero, highlights, facts, FAQs, policies text, SEO, slug) and room type content (`room_types`: description, size, bed setup, amenities, photos, SEO, slug). `day_plan` is dropped.

**H21 — Waitlist.** `waitlist_entries` keys on `room_type_id + check_in + check_out` instead of `departure_id + cabin_category`. `OfferWaitlistCabins` becomes "when nights free up that cover an entry's whole stay, notify".

**H22 — Engine contract.** The engine stops reading a departures feed. New public endpoints (rate-limited like today):
- `GET /api/engine/property` — property + room type content (cached, versioned by the existing feed-version mechanism).
- `GET /api/engine/calendar?from=YYYY-MM&months=n&adults&children` — per night: `available` (bool), `from_price` (lowest nightly), `closed_to_arrival`, `closed_to_departure`, `min_stay`.
- `GET /api/engine/availability?check_in&check_out&adults&children&rooms` — bookable room types with full-stay quote per rate plan, or a reason per type (`SOLD_OUT`, `MIN_STAY:n`, `CLOSED_TO_ARRIVAL`, `OVER_OCCUPANCY`, `NO_RATE`).
- `POST /api/engine/quote`, `POST /api/engine/checkout` — body carries `check_in`, `check_out`, and per room `{room_type, adults, children, child_ages, rate_plan}`.

**H23 — Offers and promos.** Offer validity uses a **stay window** (`stay_from`, `stay_to`, applied per night that falls inside) and a **booking window**, plus optional `min_nights`. Replaces "travel date = departure date".

**H24 — References.** `DEP-…` sequences stop being drawn. Booking references (`ANK-YYYY-NNNN`) keep their format; `YYYY` remains the year of sale (unchanged rule). Prefix change is a client decision (HQ12) and a one-line config change in `ReferenceService`.

## 4. Target schema (summary)

```
properties          id, code, name, slug, timezone(null=business tz), address…, content…, audit
room_types          id, property_id, code, name, base_occupancy, max_occupancy, max_adults,
                    max_children, waitlist_enabled, sort, status, content…, audit
rooms               id, property_id, room_type_id, code, label, floor, sort, status, audit
room_night_claims   id, room_id, night, holder_type, holder_id, kind, hold_type, expires_at,
                    released_at, release_reason, active_key (stored, unique), audit
stay_restrictions   id, property_id, room_type_id null, night, stop_sell, closed_to_arrival,
                    closed_to_departure, min_stay null, max_stay null, note null, audit
                    unique(property_id, room_type_id, night)
internal_blocks     + room_id, starts_on, ends_on (exclusive)
bookings            + property_id, room_type_id, room_id (renamed cabin_id), check_in, check_out,
                    nights, rate_plan_code, night_lines(json), expected_arrival_time,
                    checked_in_at, checked_out_at, no_show_at
                    − departure_id, back_to_back, png_collected  (dropped in Sprint 22)
waitlist_entries    + room_type_id, check_in, check_out   − departure_id, cabin_category
offers              + stay_from, stay_to, min_nights
```

## 5. Open questions (ask the client; do not guess)

| Id | Question | Safe default until answered |
|---|---|---|
| HQ1 | Keep exclusive-use (buyout) bookings? | Flag off, code kept until Sprint 22 decision |
| HQ2 | Day-use rooms (0 nights)? | Not supported |
| HQ3 | Standard check-in / check-out times, no-show cut-off | Demo values `15:00` / `11:00` / `23:59`, labelled demo |
| HQ4 | Split-room stays? | Not supported |
| HQ5 | Min-stay on arrival vs "through"? | On arrival |
| HQ6 | Separate "hidden" vs "closed" per date; public per-date note? | One `stop_sell` flag, no note on engine |
| HQ7 | No-show: release remaining nights or keep the room? | Release from `check_in + 1` |
| HQ8 | Penalty for shortening a stay | Cancellation bands applied to removed nights |
| HQ9 | Guest registration fields per jurisdiction | Name, nationality, DOB, document no., arrival, departure |
| HQ10 | Multi-currency | USD only |
| HQ11 | Multiple time zones | One business time zone |
| HQ12 | Booking reference prefix | Unchanged |
