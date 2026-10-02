# 16-08 — Sprint close: e2e and report

**Repo:** iconic-api
**Depends on:** 16-01 … 16-07
**Read first:** `tests/e2e/README.md`, `tests/e2e/scenarios/INDEX.md`, `_TEMPLATE.md`

## Build
1. New scenarios (folder `tests/e2e/scenarios/hotel/`):
   - **HSET-01 · Properties and room types in the RMS** (P1): Carolina opens the property, edits the description, adds a room type, adds a room to it, deactivates an empty room type; history shows each change once.
   - **HSET-02 · Stay rules on the Business Rules page** (P1): the "Stay" group shows eight values; changing check-in time requires a reference and publishes a new version.
2. Update INV-01 and INV-12 for the Property/Room labels (same task that changed the label, per core rules — note in report if 02/03 already did it).
3. Add the ids to `INDEX.md` and to this README's E2E line.
4. Run the P1 set (`tests/e2e/bin/up.sh`, wait for `ALL UP`). Record results in `runs/LEDGER.md`.
5. Close `REPORT.md`: summary, open questions raised (HQ ids), git commands for the user.

## Done when
All P1 scenarios pass or have a written, accepted reason in `REPORT.md`.
