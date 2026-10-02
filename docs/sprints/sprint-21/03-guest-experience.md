# 21-03 — Guest experience: pre-arrival questionnaire, arrivals brief, NPS after check-out

**Repo:** iconic-api, iconic-panel
**Depends on:** 19-03
**Read first:** `Support/GuestExperience/*` (`DepartureGuestExperience`, `DepartureList`, `HotelManagerBrief`, `QuestionnairePage`, `SurveyPage`), `NpsSurveyCommand.php`, business rules `nps`, panel `rms/operations/guest-experience.vue`

## Build
1. Questionnaire send date = `check_in − <existing days rule>`; page shows the stay.
2. `HotelManagerBrief` → `ArrivalsBrief` per date: arrivals with preferences, dietary/accessibility (permission-gated as today), celebrations, expected arrival times. `GET /api/rms/guest-experience/arrivals?date=`. Old per-departure endpoints → 410.
3. Guest experience list: by arrival date range, not departures.
4. NPS: `nps.survey_hours_after_return` → `survey_hours_after_check_out` (config migration, same value); anchor = `checked_out_at` if set else `check_out` at `stay.check_out_time` (09 H10). `NO_SHOW` never surveyed.

## Tests
Send-date maths; brief content and gating; NPS anchor both cases; no-show excluded.

## Done when
The guest-experience page works by date with no departure selector.
