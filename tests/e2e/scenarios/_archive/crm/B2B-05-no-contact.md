# B2B-05 · An agency with no CRM contact shows the no-enrolment sentence
- **Tags:** sprint-15, crm
- **Priority:** P1
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
An agency email that matches no contact stays on the list. The page must say why the activation journey did not enrol. A blank contact cell is a failure.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/sales/b2b-partners`.
2. Find **Unmatched B2B** (`AG-004`).
3. Open that row.

## Expected
- [ ] E1 · The contact cell is the sentence `No CRM contact matches this agency, so b2b_partner_activation was not enrolled.`
- [ ] E2 · There is no contact link on that row.
- [ ] E3 · The journey cell is that same sentence. It is not `CRM contact matched. b2b_partner_activation is not enrolled.`
- [ ] E4 · The drawer repeats the no-contact sentence. Status stays `PENDING`. Revenue and commission accrued render as money, not empty cells.

## Cross-checks
- `bin/db-check.sh 'App\Models\Contact::query()->where("email","nobody-b2b@iconic.test")->exists()'` → false.
- `bin/db-check.sh 'App\Models\Agency::query()->where("reference","AG-004")->value("email")'` → `nobody-b2b@iconic.test`.

## Notes
`seedUnmatchedAgency()` inserts AG-004 outside the `ResolveContact` loop and does not dispatch `AgencyApproved`. AG-001, AG-002, and AG-003 are the matched-but-not-enrolled rows. This scenario is the other state.
