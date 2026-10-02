# VIS-01 · Pages against the prototype
- **Tags:** visual, sprint-2
- **Priority:** P3
- **Users:** Carolina
- **Start:** reset
- **Needs:** prototype server

## Why
The panel must look like `prototype/rms_index.html`. Deliberate deviations already listed in sprint reports are notes, not failures.

## Steps
1. From `iconic-api`, serve the prototype:
   `python3 -m http.server 8090 --directory docs/requirements/prototype`
2. Open `http://localhost:8090/rms_index.html` (screens `v-rates`, `v-eset`, `v-rules`, and the permissions/matrix views if present).
3. As Carolina, open in the panel: `/rms/commercial/rates`, `/rms/booking-engine/settings`, `/rms/admin/business-rules`, `/rms/admin/permissions`.
4. Compare side by side in **dark** (default) and **light**: panel order, titles, pills, table columns.
5. Capture paired screenshots of any difference.

## Expected
- [ ] E1 · Rates: same panel order (notice, base rates, deposit, discount rules, price check, extras, promotions, history). Titles match the i18n strings in this repo.
- [ ] E2 · Engine Settings: six panels in the order Guests · Calendar · Language · Notes · Confirmation · Fees · Charter.
- [ ] E3 · Business Rules: KPI row, chips, table columns `Source · Rule · Current value · Source value · Used in`.
- [ ] E4 · Permissions: team table + matrix title `Permission matrix — enforced server-side on every mutation`.
- [ ] E5 · Differences **already listed as deliberate** in sprint-01 / sprint-02 REPORT.md are recorded as notes, not failures (no `ENGINE UP TO DATE` pill; sticky publish bar; six PNG fields; data-retention group; etc.).

## Notes
Report paired screenshots for anything else. Do not fail the run for the documented deviations.
