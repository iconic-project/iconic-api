# 21-05 — CRM: derived stay data, segments, journeys, templates

**Repo:** iconic-api, iconic-panel
**Depends on:** Sprint 19
**Read first:** core rule 1 (CRM has no write path to money), `laravel.mdc` CRM arch test; `Support/Crm/ContactDerived.php` (the SQL using departure date + nights), `DealDrawer.php`, `EngineActivity.php`, `Support/Contacts/*`, `Enums/SegmentDimension.php`, `Support/Journeys/JourneyClock.php`, `JourneyEngine.php`, `Enums/JourneyStepAction.php`, `Support/Templates/TemplateRenderer.php`, `Enums/TemplateVariable.php`

## Build
1. `ContactDerived`: `last_stay_check_out`, `next_stay_check_in`, `stays_count`, `nights_count`, `lifetime_value` (unchanged definition), `last_room_type`, computed from `bookings` columns directly (no departures join). Replace the `DATE_ADD(departures.date, INTERVAL nights)` SQL with `bookings.check_out`.
2. Segments: dimensions "stay date", "arrival weekday", "length of stay", "room type", "rate plan" replace departure/itinerary dimensions. Only add enum cases that 09 or the client approves; otherwise implement the mapping and list proposals in REPORT.
3. Journeys: clock anchors `ARRIVAL` (check_in) and `CHECK_OUT` replace departure/return anchors; `JourneyEngine` "trip started" = `today ≥ check_in`. Migrate stored journey steps that reference the old anchors (data migration, history of the journey kept).
4. Templates: variables `{{check_in}}`, `{{check_out}}`, `{{nights}}`, `{{room_type}}`, `{{property_name}}`, `{{check_in_time}}`, `{{check_out_time}}`; old `{{departure_date}}` keeps rendering `check_in` with a deprecation note in the template editor until Sprint 22.
5. Deal drawer and engine activity show stays and searches (from 20-02 events).
6. CRM arch test still passes; `assertNoSensitiveFields()` on every changed endpoint.

## Tests
Derived values on the fixture; segment filters; journey anchor maths; template rendering; CRM write-path arch test.

## Done when
No CRM query joins `departures`.
