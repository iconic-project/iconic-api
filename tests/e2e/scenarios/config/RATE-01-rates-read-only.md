# RATE-01 · Rates read-only
- **Tags:** sprint-2, config
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
Sales Exec must not be able to edit prices. The page is still useful for the price check.

## Steps
1. The seed is Hotel Demo. The price-check totals in E4 are the hotel reference stays.
2. Sign in as `lucia@iconic.test` / `password`. Open `http://localhost:3001/rms/commercial/rates`.
3. Read the top bar, the **Seasons** table, and **Price check — published vs your draft**. Open **Legacy (yacht) — read only** only far enough to see that its inputs are disabled.

## Expected
- [ ] E1 · State line is `VIEW ONLY — ADMIN / DIRECTOR EDITS RATES`.
- [ ] E2 · There is no approval field (`Approval ref / reason (required)` is absent) and no `Save & publish`.
- [ ] E3 · Season code, name, from, and to inputs are disabled. There is no `Add a season`. The legacy block's inputs are disabled.
- [ ] E4 · The price-check card `STD · 2026-02-02 · 2 nights` shows room total `USD 200` and difference `no change`. There is no **Sailing year** control.

## Notes
Rates view-only copy is hard-coded (admin/director), unlike Engine Settings which uses the role name. The eight stay totals are **HRATE-01**. This scenario only checks that Lucía can read one of them.
