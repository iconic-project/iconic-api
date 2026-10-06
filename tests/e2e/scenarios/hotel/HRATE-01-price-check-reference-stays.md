# HRATE-01 · Price check matches the reference stays
- **Tags:** sprint-18, hotel, config
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The eight hotel `reference_quotes` are the nightly pricing contract. If the draft total is wrong, every stay quote is wrong.

## Steps
1. The seed is Hotel Demo. Do not accept a sailing-year selector or the old yacht totals (`USD 26,600` and the rest).
2. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/commercial/rates`.
3. Scroll to **Price check — published vs your draft**. It lists eight stays, prefilled. Do not edit them. Read each card's room total, amount due, deposit line, and difference. Totals are `docs/requirements/examples/hotel-seed-data.json` `reference_quotes`, rendered as `USD` integers.

## Expected
- [ ] E1 · There is no **Sailing year** control. Each card's difference is `no change`.
- [ ] E2 · `STD · 2026-02-02 · 2 nights`, adults `2`, plan Best available (BAR). Room total `USD 200`. Amount due `USD 200`. Deposit `30% · USD 60`. Cancellation `standard`. Night lines `2026-02-02` and `2026-02-03`, season Low, `USD 100` each.
- [ ] E3 · `FAM · 2026-04-06 · 1 night`, adults `3`, plan BAR. Room total `USD 260`. Summary includes Extra adult `USD 40`.
- [ ] E4 · `FAM · 2026-04-06 · 1 night`, adults `2`, child ages `6`, plan BAR. Room total `USD 240`. Summary includes Extra child `USD 20`. The age `6` is the published `guests.child_min_age`, not a typed business rule.
- [ ] E5 · `STD · 2026-02-02 · 1 night`, adults `1`, plan BAR. Room total `USD 90`. Summary includes Single occupancy `USD -10`.
- [ ] E6 · `STD · 2026-07-03 · 1 night`, adults `2`, plan BAR. Room total `USD 220`. Summary includes Day of week `USD 20`.
- [ ] E7 · `STD · 2026-12-24 · 1 night`, adults `2`, plan BAR. Room total `USD 300`. Summary includes Festive `USD 50`.
- [ ] E8 · `STD · 2026-02-02 · 1 night`, adults `2`, plan Non-refundable (NR). Room total `USD 90`. Summary includes Non-refundable `USD -10`. Deposit `100% · USD 90`. Cancellation `non_refundable`.
- [ ] E9 · `STD · 2026-02-02 · 7 nights`, adults `2`, plan BAR. Room total `USD 648`. Summary includes Length of stay. Amount due equals the room total on every card (the hotel seed has no taxes).
