# BR-01 · Fresh-seed registry
- **Tags:** sprint-2, config
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The registry counts are the contract for “are we still seeding the documented rules?” Sprint 13 added `portal.invite_valid_days` (PENDING CLIENT, 14 days).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/admin/business-rules`.
2. Read the four KPIs and the filter chips. Open **Differs / flagged**.

## Expected
- [ ] E1 · KPIs: `Rules tracked` = **102**; `Adjusted here` = **77**; `Set in other tabs` = **15**; `Differ from source / flagged` = **56**. ⚠ UNVERIFIED — `BusinessRulesEndpointsTest` / `Registry::counts()`, not a reset screen.
- [ ] E2 · Chip counts match: All 102 · Adjust here 77 · Set in other tabs 15 · Locked 10 · Differs / flagged 56. ⚠ UNVERIFIED — same source.
- [ ] E3 · Flagged rows are the pending statuses plus confirmed notes: `TEXT IN DRAFTING`, four × `PENDING LEGAL` (passport, medical, behavioural raw, unstitched anonymous), forty-five × `PENDING CLIENT` (the previous pending-client set, including manifest chase and the NPS review URL, eight Stay rows, and guest registration — HQ9). OPS-006 is `CONFIRMED` with a note (⚠). OPS-001 duration is `CONFIRMED` with a retired note pointing at 09 H2. DPNG manifest and the captain's manifest are `CONFIRMED` with a retired note pointing at 09 H15. ⚠ UNVERIFIED — `Registry.php` + Pest (`differs_or_flagged` 56), not a reset screen.
- [ ] E4 · No confirmed `here` row shows `≠ differs from source` on a fresh seed.

## Notes
Values: `fixtures/reference-values.md` (Registry facts). Issuer rows are CONFIRMED (decision 8). Bank rows and the five consent-version rows are PENDING CLIENT (LEG-004 / LEG-001 / LEG-002 / OPS-005). CRM segment HIGH / MID and the two L6 behavioural-retention rows are also pending.
