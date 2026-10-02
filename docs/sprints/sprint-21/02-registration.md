# 21-02 — Retire manifests; front-desk lists and guest registration export

**Repo:** iconic-api, iconic-panel
**Depends on:** 19-03, 19-07
**Read first:** 09 H15, H16, HQ9; `Support/Manifests/*`, `Models/Manifest.php`, `Enums/Manifest*.php`, `ManifestController`, `ManifestsDueCommand.php`, `Support/Operations/ManifestsDue.php`, business rules `manifests`

## Build (api)
1. Business rules `registration`: `{fields: list<field code>, formats: [CSV, PDF], deadline_hours_after_check_in: int|null}`. Field codes from the guest model only (`full_name`, `nationality`, `dob`, `document_type`, `document_number`, `address`, `check_in`, `check_out`, `room`). Defaults per HQ9. Config migration `Sprint 21: registration added (09 H15)`.
2. `GuestRegistrationExport` — `GET /api/rms/front-desk/registration?date=…&format=csv|pdf`: guests of bookings in house on that night; sensitive fields included only with `guests.view_sensitive` (existing permission) and the export is recorded in history (`registration.exported`, who/when/date, no values).
3. `FrontDeskLists` API: `GET /api/rms/front-desk?date=` → arrivals, in house, departures (the panel page from 19-07 switches to this endpoint if it used ad-hoc queries).
4. Retire: `ManifestsDueCommand` removed from the schedule; manifest create endpoints return 410; existing manifests remain readable (history). `manifests` business-rules keys marked legacy.
5. Guest data completeness (`Support/Guests/GuestIssues.php`) measured against `registration.fields` and `check_in` instead of DPNG requirements.

## Build (panel)
Front desk page: "Export registration" button (format choice); Operations menu: "Manifests" removed, existing manifests reachable read-only from booking history.

## Tests
Export field selection, sensitive gating, history entry without values, guest issues by registration fields.

## Done when
No scheduled job creates or chases a manifest.
