# HRATE-05 · City tax shown, not charged
- **Tags:** sprint-18, hotel, config
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A tax can appear on the price check and stay out of the amount due when it is shown and not charged.

## Steps
1. Hotel seed (`ICONIC_SEED_MODE=hotel` before `reset.sh`). The seeded taxes list is empty.
2. As Carolina, open `/rms/admin/business-rules`. Find **Taxes and fees** (source `09 H9`). The current display is `none`.
3. `Add a tax`. Code `CITY`, label `City tax`, basis `Per stay`, amount `25`. Leave **Charged** unchecked. Leave **Shown in price** checked.
4. Top bar approval `E2E-HRATE-05`. `Save & publish`. Confirm `Publish these changes?`
5. Open `/rms/commercial/rates`. On the price-check card `STD · 2026-02-02 · 2 nights` (adults `2`, plan BAR), read the tax row and the note.

## Expected
- [ ] E1 · Business rules publishes. Toast `Version {M+1} published`, where `M` is the version in the state line before this publish. Do not assume `M` is `1`.
- [ ] E2 · The stay shows a tax row `City tax`, the words `not charged`, and `USD 25`.
- [ ] E3 · Room total stays `USD 200`. Amount due stays `USD 200`. The `USD 25` is not added to the amount due.
- [ ] E4 · A second tax with **Shown in price** unchecked would not appear. Do not add one. This scenario only checks the shown, uncharged row.

## Notes
`25` is the amount typed in this scenario. It is not a seeded business value. The hotel fixture's taxes list is empty.
