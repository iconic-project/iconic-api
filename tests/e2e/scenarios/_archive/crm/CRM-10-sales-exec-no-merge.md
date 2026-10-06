# CRM-10 · Sales Exec sees all contacts but cannot merge
- **Tags:** sprint-9, crm
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

## Why
Contacts are shared people records (L1). Merge is Admin / Manager only. A Sales Exec with `contacts.manage` must still see every contact and must not see Merge.

## Steps
1. Sign in as `lucia@iconic.test` / `password`. Header `LUCÍA B. — SALES EXEC`. Open `http://localhost:3001/crm/sales/contacts`.
2. Confirm the list includes contacts whose bookings Lucía does not own (at least **The Brandt Family** / Mateo’s `ANK-2026-0005`, **Vandermeer Charter** / Carolina’s `ANK-2026-0012`, **S. Ferreira**).
3. Open **A. Fontaine**. Confirm **Edit** is present. Confirm there is no **Merge** / **Undo** control.
4. If a previous scenario left a duplicate pair on this reset it will not — this start is reset, so create the same shared phone as CRM-04 steps 2–4 (Anna Whitfield and K. Osei, `+1 650 253 0000`, country `US`) and reload.
5. Read **Possible duplicates**. Confirm **Merge** is absent on the pair.

## Expected
- [ ] E1 · Lucía opens `/crm/sales/contacts` (she has `panel.crm`). The list is the same people CRM-01 shows, including bookings she does not own. ⚠ UNVERIFIED — L1 / `ContactPolicy::viewAny`.
- [ ] E2 · Drawer **Edit** is present (`contacts.manage`). Save is present.
- [ ] E3 · No **Merge** button on the list, the duplicates row, or the drawer. No merge modal. Undo is absent.
- [ ] E4 · After the shared-phone pair: **Possible duplicates** lists the two names and **Same phone**, with no **Merge** action.

## Notes
One user per context. Do not stay signed in as Carolina. Lucía’s seeded role has `contacts.manage` and does not have `contacts.merge` (`SystemRole::SalesExec`).
