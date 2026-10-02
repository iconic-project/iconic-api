# 21-04 — Alerts and retention on the stay clock

**Repo:** iconic-api
**Depends on:** 19-03
**Read first:** 09 H10, H15; `Support/Alerts/*`, `Enums/AlertKind.php`, `OccupancyCheckCommand.php`, `AlertsCommand.php`, `Support/Retention/*`, `RetentionCommand.php`, business rules `alerts`, `retention`

## Build
1. Low occupancy: for the next `alerts.low_occupancy_days_before` nights, raise one alert per **run of consecutive nights** below `low_occupancy_pct` (`NightAvailability::occupancy`). New business rule `alerts.low_occupancy_min_consecutive_nights` (default from fixture; config migration). Alert kind: reuse if the enum has a fitting value, otherwise add `LOW_OCCUPANCY_NIGHTS` and note it.
2. Night-audit alerts from 19-03 get their `AlertKind`s and appear on the alerts page.
3. Retention: `passport_months_after_cruise` → `passport_months_after_check_out`, `medical_days_after_cruise` → `medical_days_after_check_out` (config migration, same values); anchor `check_out`. `--dry-run` unchanged. The README warning about not running retention in production until the client confirms stays.
4. Every remaining `departure` reference in alerts/retention removed.

## Tests
Consecutive-run grouping (one alert for 5 low nights, two for 2+gap+2); retention anchors; dry-run writes nothing.

## Done when
`grep -rn departure app/Support/{Alerts,Retention}` returns nothing.
