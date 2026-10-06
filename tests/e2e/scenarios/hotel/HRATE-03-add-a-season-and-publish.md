# HRATE-03 · Add a season and publish
- **Tags:** sprint-18, hotel, config
- **Priority:** P1
- **Users:** Carolina × 2
- **Start:** reset
- **Needs:** two browser contexts

## Why
A new season is published with an approval reference. A second editor who publishes first must win; the first editor's season must not overwrite that publish.

## Steps
1. The seed is Hotel Demo.
2. Context A: sign in as Carolina. Open `/rms/commercial/rates`. Note the published version `N` in the top state line (`● PUBLISHED — VN`).
3. Context A: **Seasons** → `Add a season`. Code `QUIET`, name `Quiet`, from `2026-10-01`, to `2026-12-19`. In **Room rates**, set the Standard Double (STD) cell under Quiet to `80` (the cell's accessible name is `STD · QUIET nightly`). Do **not** publish.
4. Context B: sign in as Carolina in a separate private context. Open `/rms/commercial/rates`. In **Occupancy**, change **Extra adult, per night** from `40` to `45`. Top bar approval `E2E-HRATE-03-B`. `Save & publish`. Confirm `Publish these changes?`
5. Context A: top bar approval `E2E-HRATE-03-A`. `Save & publish`. Confirm if the modal opens.
6. Context A: the conflict text and `Load the latest version` appear in the warnbox under the top bar, not a toast. Click `Load the latest version`. Confirm `Discard your unsaved edits and load the latest published version?`
7. Context A, on the loaded version: add Quiet again (same code, name, dates) and set STD · Quiet to `80`. Approval `E2E-HRATE-03-A2`. `Save & publish`. Confirm. Scroll to **Rate publish history**.

## Expected
- [ ] E1 · Context B's toast is `Version {N+1} published`. The confirm list includes `Extra adult / night`.
- [ ] E2 · Context A sees `Someone published a newer version (v{N+1}) while you were editing. Reload to see it; your changes were not saved.` and `Load the latest version`.
- [ ] E3 · After load, Extra adult is `45`. There is no Quiet row. STD has no Quiet column. A's `80` was not saved.
- [ ] E4 · Context A's second publish toasts `Version {N+2} published`. The confirm list includes `Season Quiet from`, `Season Quiet to`, and `STD · QUIET nightly`. History shows approval `E2E-HRATE-03-A2` and, above it, `E2E-HRATE-03-B`. There is no history row for `E2E-HRATE-03-A`.
- [ ] E5 · Coverage year `2026`: October and November are no longer `Gap`. December is covered for all 31 nights (Quiet through 19 December, Peak from 20 December).

## Notes
`N` is whatever the hotel seed published, not a hardcoded `1`. Yacht year columns stay inside **Legacy (yacht) — read only** and are not the edit used here.
