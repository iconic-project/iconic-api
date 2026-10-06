# HRATE-04 · Rate plan changes deposit and cancellation
- **Tags:** sprint-18, hotel, config
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
The plan on a stay sets the deposit and the cancellation set. Changing the plan in the draft must change the price check without a publish.

## Steps
1. The seed is Hotel Demo.
2. As Carolina, open `/rms/commercial/rates`. In **Rate plans**, read the BAR and NR rows. Do not change them yet.
3. In **Price check**, find `STD · 2026-02-02 · 1 night` with adults `2` and plan Non-refundable (NR). Read the note under its night lines. Find `STD · 2026-02-02 · 2 nights` with adults `2` and plan Best available (BAR). Read that note.
4. In the NR row of **Rate plans**, set **Deposit** from `100` to `50` and **Cancellation set** from `non_refundable` to `standard`. Leave **Adjust** at `-10`. Do not publish.
5. Re-read the NR one-night card and the BAR two-night card.

## Expected
- [ ] E1 · Before the edit, the NR card says Room total `USD 90`, Deposit `100% · USD 90`, Cancellation `non_refundable`. The BAR two-night card says Room total `USD 200`, Deposit `30% · USD 60`, Cancellation `standard`.
- [ ] E2 · After the edit, the NR card says Deposit `50% · USD 45` and Cancellation `standard`. Room total stays `USD 90`. Difference stays `no change` (the difference is the room total, and the published room total is still `USD 90`).
- [ ] E3 · The BAR two-night card is unchanged: Deposit `30% · USD 60`, Cancellation `standard`, room total `USD 200`.
- [ ] E4 · Nothing was published. The state line is unsaved. Discard returns the NR deposit to `100` and the cancellation set to `non_refundable`.

## Notes
Meal plan stays the code `RO` on both rows. Do not invent a marketing name for it. `09 H8` leaves meal-plan labels open.
