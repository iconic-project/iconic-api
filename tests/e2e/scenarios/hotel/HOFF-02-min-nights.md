# HOFF-02 · Min nights skips a short stay
- **Tags:** sprint-22, hotel, offers
- **Priority:** P2
- **Batch:** B28
- **Users:** Carolina
- **Start:** reset

## Why
Min nights is the length of the whole stay. A shorter stay does not take the offer.

## Steps
1. Hotel seed. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/booking-engine/offers`.
2. Click **＋ New offer**. Code `E2EHOFF2`. Internal name `E2E five nights`. Benefit type **Percent off cabin rate**. Value `10`. Channel **D2C — public engine**.
3. Stay window: arrival `2026-12-21`, departure `2026-12-26`. Min nights `5`. Leave room types and rate plans unticked. Price line `Five nights −10%`. Click **Save**.
4. Open the drawer and click **Approve**. Reason `E2E approve E2EHOFF2`. Submit.
5. Open **＋ New reservation**. Quote Standard Double, Best available, 2 adults, check-in `2026-12-21`, check-out `2026-12-25` (four nights). Read the lines.
6. Change check-out to `2026-12-26` (five nights). Read the lines again.

## Expected
- [ ] E1 · The offer is **LIVE** after approval.
- [ ] E2 · The four-night quote does not include `Five nights −10%`.
- [ ] E3 · The five-night quote includes `Five nights −10%`.

## Notes
Min nights is the whole stay, not the count of nights inside the window. Do not type a total.
