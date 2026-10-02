# PRIV-01 · Marketing opt-in lands on the register and the contact
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset

## Why
The register is the consent record. The engine form is one capture point. Analytics from a stitched session is another.

## Steps
1. **Guest.** Analytics on, then one page view, then pay later with marketing opted in. Email `e2e.priv01@iconic.test`.
2. **Carolina.** Open `http://localhost:3001/crm/system/consent`. Read the Marketing and Analytics rows.
3. Open the contact. Read the consent block, then open **History**.

## Expected
- [ ] E1 · Marketing contacts count is one higher than the fresh-seed register. The current marketing row is **OPTED IN**. Its history row has capture point `ENGINE_FORM` and the IP column shows `recorded`.
- [ ] E2 · Analytics is **OPTED IN**, capture point `ENGINE_BANNER`, when the first event was captured. ⚠ UNVERIFIED — task 09 on a non-reset database; the version string is the published analytics version.
- [ ] E3 · Transactional stays **ALWAYS ON**.
- [ ] E4 · The booking has one I6 row `document` MARKETING, `withdrawn` false, and one register row purpose MARKETING, capture point `ENGINE_FORM`, `source_consent_id` pointing at that I6 row.

## Cross-checks
- `bin/db-check.sh 'App\Models\Consent::query()->where("booking_id", App\Models\Booking::query()->where("request_reference", "<the new ANK-R>")->value("id"))->where("document","MARKETING")->where("withdrawn", false)->count()'` → `1`.
- The register row for that contact and MARKETING has `capture_point` `ENGINE_FORM`.

## Notes
Do not record a staff consent in this scenario. The register page is visible to every CRM user. The timeline shows the register line, not a second I6 marketing line.
