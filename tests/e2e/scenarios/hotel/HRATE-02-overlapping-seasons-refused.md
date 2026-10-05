# HRATE-02 · Overlapping seasons refused
- **Tags:** sprint-18, hotel, config
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Two seasons must not share a night. The API refuses the document, and the page must not publish it.

## Steps
1. Hotel seed (`ICONIC_SEED_MODE=hotel` before `reset.sh`).
2. As Carolina, open `/rms/commercial/rates`. Publish controls are the bar at the **top** (state line, `Approval ref / reason (required)`, Discard, `Save & publish`).
3. In **Seasons**, the Shoulder row **From** is `2026-04-01` and Low **To** is `2026-03-31`. Change Shoulder **From** to `2026-03-15`.
4. Wait until the warnbox under the top bar updates. Do not publish.
5. Set Shoulder **From** back to `2026-04-01`.

## Expected
- [ ] E1 · While Shoulder starts on `2026-03-15`, `Save & publish` is disabled. The seasons panel and the warnbox both contain `Seasons LOW and SHOULDER overlap.` The warnbox line is prefixed `✕`.
- [ ] E2 · The state line is `● UNSAVED CHANGES` (no change count).
- [ ] E3 · After Shoulder **From** is `2026-04-01` again, the overlap sentence is gone and the state line is published (`● PUBLISHED — V… · … · System` or Carolina, matching whoever published the current version). `Save & publish` stays disabled because the draft matches the published document.
- [ ] E4 · A soft gap warning may stay in the warnbox (`⚠`, nights outside every season). That warning does not by itself disable publish.

## Notes
Touching ranges are valid: Low ending `2026-03-31` and Shoulder starting `2026-04-01` is not an overlap. Do not use that pair as the failure.
