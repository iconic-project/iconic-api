# 22-05 — Frontends: remove yacht pages and types

**Repo:** iconic-ui, iconic-panel, iconic-engine, iconic-portal
**Depends on:** 22-04

## Build
1. `iconic-ui`: `pnpm types:api` against the cleaned API; remove now-dead composables/components; bump the layer version and update `CHANGELOG.md` (breaking: removed departure/cabin types).
2. `iconic-panel`: delete `yacht-layout.vue`, `rms/booking-engine/departures.vue`, `itineraries.vue`, manifests UI, yacht menu entries, legacy rates sections; i18n keys pruned; the same vocabulary rule as 22-04 as an ESLint `no-restricted-syntax`/custom rule over `app/` (string literals and identifiers), with the same allowlist idea.
3. `iconic-engine`: delete `itineraries/*`, `book/cabins.vue`, `charter.vue` (Path B) and any redirect older than one release; keep 301 redirects from old public URLs (`/itineraries/:slug` → `/rooms`) for SEO.
4. `iconic-portal`: remove departure-based leftovers.
5. Each repo's README: hotel wording.

## Done when
All four repos `lint`, `typecheck`, `test` green; no route renders the word "yacht" or "cabin" (checked by a Vitest that walks the i18n files).
