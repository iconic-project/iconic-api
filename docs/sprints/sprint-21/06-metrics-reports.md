# 21-06 — Metrics and reports: hotel KPIs

**Repo:** iconic-api, iconic-panel
**Depends on:** Sprint 19
**Read first:** `Support/Metrics/*`, `MetricsController`, `Support/Reports/*`, `Jobs/Reports/*`, `Enums/ReportCadence.php`, `ReportFormat.php`, panel `rms/commercial/dashboard.vue`, `reports.vue`

## Build
1. `HotelKpis` (pure over booking rows + `NightAvailability`), for a night range and optional room type / channel:
   - **Occupancy %** = room-nights sold / room-nights available (blocked excluded — same definition as 17-04),
   - **ADR** = room revenue / room-nights sold (room revenue = Σ night_lines totals, excluding taxes and extras; legacy bookings: total ÷ nights, flagged),
   - **RevPAR** = room revenue / room-nights available,
   - **Pickup** = room-nights sold for the range, created in the last N days,
   - **Average length of stay**, **lead time** (sold_on → check_in), **cancellation rate**, **no-show rate**, channel mix.
   Revenue is recognised **per night** (a stay crossing month end splits).
2. Dashboard: KPIs for this month / next 30 / next 90 nights with on-the-books vs same time last year (when data exists).
3. Reports: occupancy & revenue by night (CSV/XLSX), pace report, arrivals forecast; existing subscription/cadence mechanics unchanged. Remove departure-based reports from the catalogue (keep runs already generated).
4. Integer maths: ADR/RevPAR as integer USD, half-up; percentages one decimal in display only.

## Tests
Hand-computed KPI fixtures (month-crossing stay, blocked room, no-show, cancelled); report file columns.

## Done when
The dashboard shows Occupancy, ADR and RevPAR for the hotel fixture matching the hand-computed test values.
