# 18-06 — Panel: rates editor and price check

**Repo:** iconic-panel
**Depends on:** 18-01 … 18-05; regenerated API types in `iconic-ui`
**Read first:** `app/pages/rms/commercial/rates.vue` and its components, `rms/admin/business-rules.vue`

## Why
Admins must be able to read and publish the new document without editing JSON.

## Build
1. Rates page sections: **Seasons** (table + a 12-month strip showing coverage and gaps), **Room rates** (matrix room types × seasons), **Occupancy**, **Day of week**, **Length of stay**, **Supplements**, **Rate plans**. Legacy yacht sections collapsed under "Legacy (yacht) — read only".
2. Validation messages come from the API `validate` endpoint (server is the source of truth); soft warnings shown as such.
3. **Price check** panel: list of stays (prefilled from fixture examples returned by the API), each with `AnkStayInput`, room type, adults, child ages, plan → shows night lines, summary lines, taxes, total, and the difference vs the published version.
4. Business Rules page: "Taxes and fees" editor (list, basis select, charged / shown toggles) and "Cancellation sets".
5. Publish flow unchanged (reference required where it is today).

## Tests
Vitest: season strip gap detection display; matrix editing emits the right document; price check renders night lines.

## Done when
Carolina can add a season, price it, run a price check and publish, entirely from the UI.
