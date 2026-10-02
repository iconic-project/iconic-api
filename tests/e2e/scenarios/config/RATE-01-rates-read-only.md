# RATE-01 · Rates read-only
- **Tags:** sprint-2, config
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
Sales Exec must not be able to edit prices. The page is still useful for the price check.

## Steps
1. Sign in as `lucia@iconic.test` / `password`. Open `http://localhost:3001/rms/commercial/rates`.
2. Scroll the base-rates table, helper area, approval row, and **Price check — published vs your draft**.

## Expected
- [ ] E1 · State line is `VIEW ONLY — ADMIN / DIRECTOR EDITS RATES`.
- [ ] E2 · Rate inputs are disabled. There is no helper row (`Annual increase` / `Round to` / `＋ Add …`).
- [ ] E3 · No approval field (`Approval ref / reason (required)` is absent).
- [ ] E4 · Price check **Published** and **Draft** match for sailing year 2027; every **Difference** is `no change`.

## Notes
Rates view-only copy is hard-coded (admin/director), unlike Engine Settings which uses the role name.
