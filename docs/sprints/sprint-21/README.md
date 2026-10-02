# Sprint 21 — Operations, documents and CRM on stays

**Theme:** everything that happened "per departure" or "after the cruise" now happens per date or per stay.

At the end of this sprint:
- confirmations, vouchers and pre-arrival documents describe stays,
- DPNG and captain manifests are retired; arrivals/in-house/departures lists and a guest registration export replace them,
- the guest questionnaire is pre-arrival, the brief is a daily arrivals brief, NPS follows check-out,
- alerts, retention and journeys run on the stay clock,
- the CRM derives last/next stay, nights and value from bookings,
- reports show hotel KPIs: occupancy, ADR, RevPAR, pickup, length of stay.

Can run in parallel with Sprint 20 once Sprint 19 is done.

**Read first (whole sprint):** 09 H10, H15, H16; `Support/Documents/*`, `Support/Manifests/*`, `Support/Operations/*`, `Support/GuestExperience/*`, `Support/Crm/*`, `Support/Journeys/*`, `Support/Templates/*`, `Support/Metrics/*`, `Support/Reports/*`, `Support/Retention/*`.

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Documents on stays](01-documents.md) | api |
| 02 | [Retire manifests; front-desk lists and guest registration export](02-registration.md) | api, panel |
| 03 | [Guest experience: pre-arrival questionnaire, arrivals brief, NPS after check-out](03-guest-experience.md) | api, panel |
| 04 | [Alerts and retention on the stay clock](04-alerts-retention.md) | api |
| 05 | [CRM: derived stay data, segments, journeys, templates](05-crm.md) | api, panel |
| 06 | [Metrics and reports: hotel KPIs](06-metrics-reports.md) | api, panel |
| 07 | [Sprint close: e2e and report](07-sprint-close.md) | api |

**E2E scenarios:** HOPS-01 … HOPS-06, HCRM-01 … HCRM-03 (new); documents/*, guests/*, crm/* updated where they mention departures.
