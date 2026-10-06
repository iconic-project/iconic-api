# SEG-01 · The nine segment counts match their lists
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B18
- **Users:** Carolina
- **Start:** reset

## Why
A segment is a rule. The number on the card is the same query as the list. Nothing stores a member list.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/marketing/segments`.
2. Read the nine cards, in order. Open each card and read the list total.

## Expected
- [ ] E1 · Nine cards, in this order: Warm dreamers, Abandoned checkout, Holding — not paid, Festive prospects, Families 6–17, Past guests — HIGH LTV, Advisors — non-producing, DACH luxury, Suppressed. Suppressed is last.
- [ ] E2 · Each card's count equals the `{total} contacts` line on its list (or `No contacts in this segment.` when the count is 0).
- [ ] E3 · Names and sentences match `fixtures/reference-values.md` (Sprint 14 · Nine segments). The Count column there is blank. Do not type a number from this screen into that file during the run.

## Notes
Marketing cards exclude suppressed contacts. Holding — not paid and Advisors — non-producing are operational. Suppressed is operational.
