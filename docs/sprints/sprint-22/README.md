# Sprint 22 — Contract and cleanup

**Theme:** finish the migration. Remove every yacht-only table, enum, config key, route and word, and lock the result with tests.

At the end of this sprint:
- offers and promos work on stay windows,
- the buyout (ex-charter) question is settled: implemented or removed,
- `departures`, `itineraries`, `cabin_claims` and every legacy column are gone,
- an Arch test forbids the old vocabulary in `app/`,
- README, rules, OpenAPI and shared UI types describe a hotel system only.

**Read first (whole sprint):** 09 §2 naming rule, H14, H19, H23, H24; `HOTEL-ROADMAP.md`; every `REPORT.md` from sprints 16–21 ("Notes for later" and deprecated aliases).

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Offers and promos on stay windows](01-offers.md) | api, panel |
| 02 | [Buyout (ex-charter): implement or retire](02-buyout.md) | api, panel, engine |
| 03 | [Drop departures, itineraries, cabin claims and legacy columns](03-drop-legacy-schema.md) | api |
| 04 | [Remove legacy code, enums and config keys; vocabulary Arch test](04-remove-legacy-code.md) | api |
| 05 | [Frontends: remove yacht pages and types](05-frontends-cleanup.md) | ui, panel, engine, portal |
| 06 | [Docs, OpenAPI and full regression](06-docs-and-regression.md) | api |

**E2E scenarios:** HOFF-01, HOFF-02, HBUY-01 (if buyout kept); full P1 regression of every hotel scenario; all remaining yacht scenarios deleted from `INDEX.md`.

## Before you start
Take a production database backup and run task 03's migrations against a **copy** of production. Record row counts before/after in REPORT. Dropping tables is irreversible.
