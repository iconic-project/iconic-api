# HSET-02 · Stay rules on the Business Rules page
- **Tags:** sprint-16, hotel, config
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The Stay group is eight HQ3 demo values. Publishing a new check-in time needs an approval reference and writes one history line.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/admin/business-rules`. Publish controls are the bar at the **top**. Chip **ALL**.
2. Find the group **Stay**. Read the eight rows.
3. Change **Check-in time** from `15:00` to `16:00`. Leave **Approval ref / reason (required)** empty. Click `Save & publish`.
4. Type approval `E2E-HSET-02`. Click `Save & publish`. Confirm `Publish these changes?`. Scroll to **Rules publish history**.

## Expected
- [ ] E1 · Group **Stay** has eight rows, each **PENDING CLIENT**, source **HQ3**: Check-in time `15:00`, Check-out time `11:00`, No-show cutoff `23:59`, Minimum nights `1 night`, Maximum nights `30 nights`, Maximum rooms per booking `5 rooms`, Check-in requires full payment `Yes`, Booking horizon `730 days`.
- [ ] E2 · With an empty approval reference, `Save & publish` does not open `Publish these changes?` and does not add a history line. The reference field is marked bad.
- [ ] E3 · After publish, history includes `Stay · Check-in time: 15:00 → 16:00` once. Approval `E2E-HSET-02`. State `● PUBLISHED — V2 · … · Carolina M.` (fresh seed is V1). The other seven Stay values are unchanged.

## Notes
Current values are `BusinessRulesDocument::initial()` stay (demo, HQ3). The panel current cell is a number input (`RulesCurrentCell`). If it cannot accept `16:00`, stop at step 3 and record that. Do not type a number into the time. The Stay group was added in task 05; tasks 02 and 03 did not touch this page.
