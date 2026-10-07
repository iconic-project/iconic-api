# HENG-02 · Min-stay reason and one-click fix
- **Tags:** sprint-20, engine
- **Priority:** P1
- **Batch:** B25
- **Users:** Guest
- **Start:** reset

## Why
A stay shorter than the arrival night's minimum says why, and one control lengthens the stay to that minimum.

## Steps
1. Hotel seed. Open `http://localhost:3000`. Click `Analytics off`.
2. Click `Reserve now`. Check-in **3 Jul 2026**, check-out **4 Jul 2026** (one night). Adults `2`. Rooms `1`. Click `Search`.
3. On a room card, click `Change to 3 nights`.

## Expected
- [ ] E1 · Before the click, cards are not bookable. The reason is `Minimum stay 3 nights from this date`. The button is `Change to 3 nights`. The fixture sets min-stay 3 on 3–5 Jul 2026 for every room type.
- [ ] E2 · After the click, the stay is 3 Jul 2026 to 6 Jul 2026 (three nights). The min-stay reason is gone. A card offers a rate plan and `Continue`.

## Notes
3 Jul 2026 is a Friday inside High season. The one-click control sets check-out to check-in plus the minimum. Do not type the new check-out by hand.
