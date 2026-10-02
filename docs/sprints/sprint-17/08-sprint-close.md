# 17-08 — Sprint close: e2e and report

**Repo:** iconic-api
**Depends on:** 17-01 … 17-07

## Build
New scenarios in `tests/e2e/scenarios/hotel/`:
- **HINV-01 · Timeline shows the hotel** (P1): seeded rooms by type, a week of nights, occupancy row.
- **HINV-02 · Block a room for three nights** (P1, Mateo): drag on the timeline, bar appears, free count drops by one on exactly three nights.
- **HINV-03 · Block conflict names the night** (P1): blocking over a sold night shows the conflict message with date and reference.
- **HINV-04 · Shorten and release a block** (P2).
- **HINV-05 · Stop-sell and min-stay** (P1): set a stop-sell week and a min-stay 3 on a Friday; the restrictions grid shows both; history entry exists.
- **HINV-06 · Lucía sees, cannot edit** (P2).

Update or retire INV-01, INV-08, INV-09, INV-10 (say which). Run the P1 set, update `LEDGER.md`, close `REPORT.md` with git commands.
