# GST-08 · Contacts In list, nationality top ten, date range
- **Tags:** sprint-6, guests
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Contacts In is the commercial view of who is travelling. Value is `charges_total` (I9). Unnamed padded slots must not appear. The date range must narrow **both** the list and the nationality bars.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/contacts-in`. Date range stays **All dates**.
2. Read **Contacts from bookings & requests** and **Guests by nationality — top 10 (§9.1)**.
3. Click preset `Year 2026`. Read both panels.
4. Click preset `Year 2027`. Read both panels. Click Harrison & Whitfield (ANK-2026-0003) to open the booking panel.

## Expected
- [ ] E1 · Date-range line `14 contacts · all dates`. Rows include Harrison & Whitfield (`ANK-2026-0003`), The Brandt Family (`ANK-2026-0005`), L. Moreau with pill `TRAVEL ADVISOR` (`ANK-R-2026-0042`), Vandermeer Charter (`ANK-2026-0012`). ⚠ UNVERIFIED — task 09 browser after reset.
- [ ] E2 · Value column is charges, not cruise-only: 0007 is above `USD 23,275`, 0009 above `USD 50,000`, 0011 above `USD 26,600` (seed extras / PNG). ⚠ UNVERIFIED — task 04 money + task 09.
- [ ] E3 · Nationalities (guests): AR 4 · US 4 · FR 3 · DE 3 · EC 2 · SE 2 · GB 2 · CO 1 · NL 1. Footnote `0 guests without nationality yet.` Charter empty pads are absent. ⚠ UNVERIFIED — task 09 browser.
- [ ] E4 · Year 2026: both panels empty (`No contacts in this date range.` / `No nationalities recorded yet.`). Year 2027 restores the 14 and the bars.
- [ ] E5 · A row opens the existing booking panel (ANK-2026-0003).

## Notes
`GET /api/rms/contacts-in` + `/nationalities`. Named guests only (`Guest::scopeNamed`). Seeded Lucía still has `bookings.view_all`, so 🔒 may not show on her rows as Admin. Contacts In stays the RMS commercial list; CRM people are `/crm/sales/contacts` (CRM-01).
