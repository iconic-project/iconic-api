# 22-06 — Docs, OpenAPI and full regression

**Repo:** iconic-api
**Depends on:** 22-01 … 22-05

## Build
1. `README.md`: hotel product description, seed mode removed, new commands (`iconic:night-audit`, `iconic:hotel-contract-check`), removed commands.
2. `.cursor/rules/*.mdc`: remove every transitional note added in 16-01 (legacy pricing reference list, "removed in Sprint 18" markers). The rules now describe only the hotel system.
3. `docs/requirements/INDEX.md`: mark `01`–`07` sections superseded by `09` (a table: section → superseded by H-id). Do not edit `01`–`07` themselves.
4. `docs/requirements/examples/`: `hotel-seed-data.json` is the only fixture referenced by tests; `engine-*.json` examples current.
5. OpenAPI regenerated; `iconic-ui` types in sync (CI check that fails on drift, if not already present).
6. `tests/e2e/scenarios/INDEX.md`: delete yacht scenarios (files moved to `scenarios/_archive/`), keep only hotel and generic (auth, users-roles, config) scenarios, re-batch P1.
7. Run the **full P1 set** twice (fresh stack each time). Record in `LEDGER.md`.
8. Final `REPORT.md`: migration summary, every HQ still open, residual risks, git commands.

## Done when
Two consecutive full P1 runs are green and every HQ is either answered (with the 09 entry updated) or listed as open with its safe default in place.
