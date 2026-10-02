# RATE-03 · Price check matches the reference prices
- **Tags:** sprint-2, config
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The eight doc-02 totals are the pricing contract. If Published is wrong, every quote is wrong.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/commercial/rates`.
2. Scroll past Base rates, Terms and Rules (the price-check block is below the fold) to the panel titled **Price check — published vs your draft**. Set **Sailing year** to `2027`.
3. Read the **Published** column for every scenario. Compare to `fixtures/reference-values.md` — not to what you expect from memory.
4. Scroll to the **Promotions** panel (same page). Read the compact offers list and the footer.

## Expected
- [ ] E1 · Columns are `Scenario · Published · Draft · Difference`.
- [ ] E2 · `Suite · 2 adults` Published = `USD 26,600`
- [ ] E3 · `Suite · 1 adult (single)` Published = `USD 23,275`
- [ ] E4 · `Suite · 3 adults (triple)` Published = `USD 35,910`
- [ ] E5 · `Suite · 2 adults + 1 child` Published = `USD 37,905`
- [ ] E6 · `Owner's Suite · 2 adults` Published = `USD 50,000`
- [ ] E7 · `Suite · 2 adults · festive` Published = `USD 28,100`
- [ ] E8 · `Charter · 1 week` Published = `USD 199,500`
- [ ] E9 · `Charter · festive week` Published = `USD 211,500`
- [ ] E10 · On a fresh seed, every Difference is `no change`.
- [ ] E11 · **Promotions** lists the seeded offers (OPENING-27, VIRTUOSO-EARLY, ICONIC10, …). LIVE rows are not `.promo-off`. Footer `Manage in Offers →` plus the guardrail note. Pause shows on LIVE rows when `offers.manage`. ⚠ UNVERIFIED — i18n `rates.promotionsTitle` / `rates.offersLink`; not a reset screen.
