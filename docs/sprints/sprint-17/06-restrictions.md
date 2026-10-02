# 17-06 — Sell restrictions calendar

**Repo:** iconic-api
**Depends on:** 17-04
**Read first:** 09 H2, H7, HQ5, HQ6; `Enums/DepartureStatus.php`, `Actions/Departures/*`, `Support/Departures/Warnings.php`

## Why
Departure status (on sale / closed / hidden) was the yacht's sell control. Hotels control sales per date: stop-sell, closed to arrival, closed to departure, minimum and maximum stay.

## Build
1. Migration `stay_restrictions` per 09 §4, unique `(property_id, room_type_id, night)` — use a stored generated column for the nullable `room_type_id` in the unique key (MySQL treats NULLs as distinct).
2. Action `SetStayRestrictions` — input: `property_id`, `room_type_ids` (empty = property-wide), `from`, `to` (inclusive here, label it clearly), `weekdays` (optional ISO list), and the fields to set or clear. Upserts rows, deletes rows that become all-default. One history entry `restrictions.set` with the request summarised; reason optional. Permission `inventory.manage_restrictions` (new enum case; grant to Admin and Manager by migration).
3. `App\Services\Inventory\Restrictions::evaluate(RoomType, StayDates): RestrictionResult` — precedence per 09 H7 (type row > property row > business-rule default). Checks: any night `stop_sell`; arrival night `closed_to_arrival`; `check_out` date `closed_to_departure` (the row for the night **of** `check_out`); `min_stay`/`max_stay` read from the **arrival** night (HQ5). Returns all failing reasons, not just the first.
4. `NightAvailability::canBook` adds restriction reasons (`STOP_SELL`, `CLOSED_TO_ARRIVAL`, `CLOSED_TO_DEPARTURE`, `MIN_STAY:n`, `MAX_STAY:n`).
5. Permission `bookings.override_restrictions` (new; Admin, Manager): staff booking Actions may pass `override_restrictions: true` with a mandatory reason; the reason goes into the booking history entry.
6. Backfill: for each departure with status `CLOSED` or `HIDDEN`, write `stop_sell` on its nights for its property (property-wide). `CHARTER` departures are handled by their claims, no restriction.
7. `GET /api/rms/restrictions?property_id&from&to` and `PUT /api/rms/restrictions` (bulk).

## Tests
Precedence matrix; closed-to-departure evaluated on the check-out date; min-stay on arrival only; weekday filter; history; permissions; backfill from a closed departure.

## Done when
`canBook` returns every reason that applies, in a stable order (document the order in the Resource).
