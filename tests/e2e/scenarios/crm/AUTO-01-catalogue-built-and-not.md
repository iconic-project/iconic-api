# AUTO-01 · The catalogue lists every built message and marks the rest
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B18
- **Users:** Carolina
- **Start:** reset

## Why
One list of every automatic message. A row that does not send yet must say so.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/engine/automations`.
2. Read each section heading and each row: name, subject, trigger, timing, where.
3. Find the four not-built rows and **Welcome — partner approved**.

## Expected
- [ ] E1 · 58 rows. 54 are built. 4 are not built. Source: `fixtures/reference-values.md` and `AutomationsTest.php`.
- [ ] E2 · Each built row shows its name, quoted subject, trigger, timing, and location.
- [ ] E3 · These four are grey, show their note, and have no toggle: Wire instructions; Overdue — day 1; Escalation — manual review; High-value new lead. Notes match the fixture.
- [ ] E4 · Welcome — partner approved shows `The rule behind this message must not depend on a switch.` and has no toggle.

## Notes
Section headings come from `section_label` (`a · Lead capture & welcome` through `g · Internal alerts — Iconic team`). Do not invent a section that is not on the page.
