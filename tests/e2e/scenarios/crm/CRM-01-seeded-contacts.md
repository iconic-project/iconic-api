# CRM-01 · Seeded contacts with lifecycle, value, segment and consent
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The CRM list is the people record. If lifecycle, lifetime value, segment or consent are typed by hand or missing after a reset, every later CRM scenario is reading a lie.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/contacts`.
2. Read the notice, the column headers, the filter row and the hint under the table.
3. Confirm **Possible duplicates** is not on the page.
4. Find **A. Fontaine**. Read lifecycle, LTV, segment, consent and NPS.
5. Confirm these names are also on the list: Harrison & Whitfield, The Brandt Family, L. Moreau, S. Ferreira, Anna Whitfield, K. Osei.
6. Set **Lifecycle — All** to **BOOKED**. Read the remaining rows, then set it back to All.
7. Set **Consent — All** to **Marketing opted in**, then **Transactional only**. Set it back to All.
8. Open `http://localhost:3001/crm/system/consent`, then return to Contacts. Read Fontaine’s lifecycle again.
9. Open **A. Fontaine**. Read the **Journeys** section.

## Expected
- [ ] E1 · Notice: `The CRM is the system of record for people: identity, attribution, consent, lifecycle, segment, preferred channel, NPS. Passport, date of birth and medical data live only in the RMS and are never here.`
- [ ] E2 · Filters: `Type — All`, `Lifecycle — All` (options include BOOKED / SQL / AGENT / MQL / PROSPECT), `Main channel — All`, `Channel of origin — All`, `Consent — All` (`Marketing opted in` / `Transactional only`), search placeholder `Name, email or phone`.
- [ ] E3 · Columns: Name · Type · Country · Lifecycle · Main channel · Channel of origin · LTV · Segment · Consent · NPS. Hint: `Lifecycle is derived, not typed: MQL/SQL from engine + CRM behaviour, BOOKED/GUEST/PAST GUEST from the RMS booking status, AGENT from the RMS partner approval.`
- [ ] E4 · The **Possible duplicates** panel is absent (fresh seed has no pairs). The list has `duplicatesEmpty` copy but the page hides the panel when the array is empty. ⚠ UNVERIFIED — task 06 report.
- [ ] E5 · A. Fontaine: lifecycle **BOOKED**, LTV **USD 26,600**, segment **HIGH**, consent `MKT ✓`, NPS `—`. ⚠ UNVERIFIED — Sprint 9 task 06 browser, not this task.
- [ ] E6 · Named rows above are present. Each other lifecycle / LTV / segment line is ⚠ UNVERIFIED — do not invent a table from the bookings seed. S. Ferreira is **AGENT** (approved agency email). L. Moreau is **SQL** (REQUESTED). Anna Whitfield and K. Osei are waitlist-only. ⚠ UNVERIFIED — `ContactDerived` + seeders.
- [ ] E7 · Lifecycle **BOOKED** shrinks the list. Fontaine stays. L. Moreau (SQL) leaves. Clearing the filter restores the full list.
- [ ] E8 · **Transactional only** includes M. Castellanos (`ANK-2026-0007` has no marketing consent). **Marketing opted in** includes Fontaine. ⚠ UNVERIFIED — `DemoConsentsSeeder`.
- [ ] E9 · After opening the consent register, Fontaine’s lifecycle is still **BOOKED**. The register does not retype lifecycle.
- [ ] E10 · The drawer has a **Journeys** heading. On a fresh seed it says `No enrolments.`

## Notes
Values: `fixtures/reference-values.md` (CRM contacts). Do not type passport, date of birth or nationality onto this page.
