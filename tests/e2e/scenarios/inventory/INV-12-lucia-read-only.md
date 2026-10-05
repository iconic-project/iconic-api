# INV-12 · Read-only inventory for Lucía
- **Tags:** sprint-3, inventory
- **Priority:** P2
- **Users:** Lucía
- **Start:** reset

Status after sprint 17 task 07: yacht layout redirects to the night calendar. Sales Exec still has no New block, Shorten, or Release. The Year 2027 column checks below are the old grid. Task 08 rewrites them.

## Why
Sales Exec can read inventory and must not see write controls. The API also refuses writes; this scenario checks the panel.

## Steps
1. Sign in as `lucia@iconic.test` / `password` in a fresh context (sign out first if another user is signed in). Header `LUCÍA B. — SALES EXEC`.
2. Open `http://localhost:3001/rms/booking-engine/itineraries`. Open the WEST card.
3. Open `http://localhost:3001/rms/booking-engine/departures`. Read the toolbar and a status select. Open DEP-001.
4. Open `http://localhost:3001/rms/operations/blocks`. Read the toolbar and BLK-001.
5. Open `/rms/reservations/calendar` and `/rms/reservations/yacht-layout`. Set **Year 2027** on both.

## Expected
- [ ] E1 · Itineraries: no `＋ New itinerary`. Drawer notice `Sales Exec role: view only. Itinerary content is managed by Admin / Manager.` Fieldset disabled. Close only (no Save / Hide / Delete; History hidden).
- [ ] E2 · Departures: no `Generate season…`, no `＋ New departure`. Every `.tsel` status select is `disabled`. Drawer notice `Sales Exec role: view only. Departures are managed by Admin / Manager.` Cancel only.
- [ ] E3 · Blocks: no `＋ New block`, no Release buttons. Drawer notice `Sales Exec role: view only. Internal blocks are managed by Admin / Manager.` Notes as text, no edit form. History is visible (`panel.rms`).
- [ ] E4 · Calendar Year 2027 shows the eight columns, the two property headers, and the FAM cells on ANAMARA Suite 07–08. Yacht Layout (`/rms/reservations/yacht-layout`) for `14 Nov 2027` shows both decks and `Blocked · Fam trip` on those rooms. No itinerary / departure / block write controls on either page. Free cells may open New reservation because Lucía has `bookings.create`.

## Notes
Lucía rights: `fixtures/accounts.md`.

Sprint 16: inventory rows are rooms on a property. The page title stays **Yacht Layout**. Tasks 02 and 03 left this label pass for task 08.
