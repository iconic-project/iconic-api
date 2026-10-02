# 16-05 — Stay business rules

**Repo:** iconic-api
**Depends on:** 16-04
**Read first:** 09 H2, H3, H7, H11, HQ3; `laravel.mdc` "Configuration documents"; `Support/Config/Documents/BusinessRulesDocument.php`, `HoldsRules.php` (pattern for a section class), `Support/BusinessRules/Registry.php`

## Why
Check-in time, check-out time, stay length limits and the check-in payment rule are business values. Core rule 3: they live in the business rules document, versioned and published.

## Build
1. New section class `StayRules` in `Support/Config/Documents/` and key `stay` on `BusinessRulesDocument`:
   ```
   stay:
     check_in_time: "15:00"          # HH:MM, business tz
     check_out_time: "11:00"
     no_show_cutoff_time: "23:59"
     min_nights: 1
     max_nights: 30
     max_rooms_per_booking: 5
     check_in_requires_full_payment: true
     booking_horizon_days: 730
   ```
   Every value above is a **demo value** (HQ3). Mark them in `initial()` with `// TODO(OPEN: HQ3) demo value`.
2. `rules()`: times `date_format:H:i`; `min_nights ≥ 1`; `max_nights ≥ min_nights`, ≤ 365; `max_rooms_per_booking` 1–50; `booking_horizon_days` 30–1095. `labels()` for the change list. `warnings()`: soft warning when `check_out_time` is later than `check_in_time` (a room cannot be turned over on the same day).
3. Config migration publishing a new business-rules version as System with `approval_reference` `Sprint 16: stay added (defaults demo, source 09 H3)`. Update `iconic:config-verify` expectations and the seed-data assertions in the same commit (`laravel.mdc`).
4. Registry (`Support/BusinessRules/Registry.php`): add the stay rules so they show in the panel's Business Rules page; mark `ops-001-duration` ("7 nights · Sunday → Sunday") as **retired** with a pointer to 09 H2 (do not delete the entry yet — Sprint 22 does).
5. Wire `StayClock` (16-04) to read these values; remove its TODO.

## Tests
- Document validation matrix (valid, each invalid field).
- Migration publishes exactly one version; `config-verify` passes on a fresh database and on one migrated from Sprint 15.
- `StayClock::arrivalMoment` uses the published time.

## Out of scope
Using `min_nights`/`max_nights` in availability (Sprint 17) and the payment rule in check-in (Sprint 19).

## Done when
The Business Rules page lists a "Stay" group with eight values, all labelled.
