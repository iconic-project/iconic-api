# HCRM-02 · Segment by length of stay
- **Tags:** sprint-21, crm
- **Priority:** P2
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
Length of stay is a vocabulary field on the booking. It does not join a departure.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/segments`. Click **New segment**.
2. Name `E2E long stays`. Rule `At least seven nights`. Feeds `e2e`. Kind **OPERATIONAL**. Match **all**.
3. **Add condition**. Field **Length of stay**. Operator `gte`. Value `7`. **Save**.
4. Open the new card's contact list.

## Expected
- [ ] E1 · The field list offers **Length of stay**. It does not offer a departure date field.
- [ ] E2 · Save succeeds. The card is on the segments page.
- [ ] E3 · **Harrison & Whitfield** is in the list (ANK-2026-0003 is 7 nights). **Anna Whitfield** is not (no booking).

## Notes
Kind OPERATIONAL so a missing marketing opt-in does not hide the row. Do not publish this segment into a journey.
