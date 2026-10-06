# INV-11 · Rates year guard
- **Tags:** sprint-3, inventory
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Publishing rates must refuse dropping a year that still has departures, and warn when departures sail in a year with no rates.

## Steps
1. Sign in as Carolina. The Rates ✕ control only removes the **last** year column (seeded 2027 / 2028 / 2029), so 2027 cannot be clicked away. Open `/rms/booking-engine/departures` → `＋ New departure`. Date `2029-01-07` (Sunday), yacht ANAMARA, itinerary Western Realm. `Create departure`.
2. Open `http://localhost:3001/rms/commercial/rates`. On the **2029** column click ✕. Fill **Approval ref / reason (required)** with `E2E-INV-11`. `Save & publish`. Confirm `Publish these changes?`
3. Discard or leave the failed draft. Open Departures again. `＋ New departure`. Date `2031-01-05` (Sunday), yacht ANAMARA, itinerary Western Realm. `Create departure`.
4. Return to `/rms/commercial/rates`. Read the publish-bar warnings (they validate the current draft).

## Expected
- [ ] E1 · Publish is refused. Error on `years`: `Can't remove 2029 — 1 departure sails that year.`
- [ ] E2 · After the 2031 departure exists, the Rates page warns `Departures in 2031 have no rates.`

## Notes
The task file said “remove 2027”. The screen can only ✕ the last year, and cannot remove the last remaining year, so this scenario uses a 2029 departure + ✕ 2029. Same API guard (`DepartureConfigChecks`). Sentences: `fixtures/reference-values.md`. Mateo cannot run this (`rates.manage`).
**Embark date (Sunday)** is a labelled date input (`getByLabel`).
