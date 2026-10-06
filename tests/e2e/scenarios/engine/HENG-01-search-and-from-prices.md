# HENG-01 · Search a stay from the home page
- **Tags:** sprint-20, engine
- **Priority:** P1
- **Batch:** B25
- **Users:** Guest
- **Start:** reset

## Why
The home page is a stay search. The price calendar shows a from-price on nights that have a rate.

## Steps
1. Hotel seed. Open `http://localhost:3000`. Before any other click, click `Analytics off`.
2. The calendar opens on the current month. Move it until **21 Dec 2026** is visible. Read that day and **5 Oct 2026** (move again if October is off screen).
3. Choose check-in **21 Dec 2026** and check-out **25 Dec 2026**. Adults `2`. Children `0`. Rooms `1`. Click `Check availability`.

## Expected
- [ ] E1 · 21 Dec 2026 shows a from-price of USD 220. That night is Peak, and 220 is the Twin nightly (the lowest type that fits 2 adults). Monday has no day-of-week adjustment in the fixture.
- [ ] E2 · 5 Oct 2026 shows no from-price. October sits in the gap between High (ends 30 Sep 2026) and Peak (starts 20 Dec 2026).
- [ ] E3 · The results page is `/book/rooms` and the heading is `Rooms for your stay`. Standard Double, Twin, Family, and Suite are listed. Each bookable card has a stay total.

## Notes
Do not type a price. USD 220 is the published Twin Peak nightly. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload).
