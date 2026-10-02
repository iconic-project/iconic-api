# INV-05 · Departure date rules
- **Tags:** sprint-3, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Iconic only sails Sunday → Sunday, and one yacht cannot have two departures on the same date. Both checks must surface the API sentence in the drawer.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/booking-engine/departures`. Count line is `16 departures · all dates`.
2. Click `＋ New departure`. Leave yacht ANAMARA. Set **Embark date (Sunday)** to `2028-01-03` (Monday). Click `Create departure`.
3. Change the date to `2027-11-07` (already DEP-001 on ANAMARA). Click `Create departure` again.

## Expected
- [ ] E1 · Monday create stays on **New departure** and shows `Iconic sails Sunday → Sunday. 3 Jan 2028 is not a Sunday.`
- [ ] E2 · Duplicate create stays on **New departure** and shows `ANAMARA already has a departure on 7 Nov 2027 (DEP-001).`
- [ ] E3 · The list is still `16 departures · all dates`.

## Notes
Copy: `App\Support\Dates\Format::calendar` and `YachtDateConflict`. Values: `fixtures/reference-values.md`.
