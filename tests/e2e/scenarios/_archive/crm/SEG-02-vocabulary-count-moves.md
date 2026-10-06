# SEG-02 · A vocabulary segment moves when a contact changes
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B18
- **Users:** Carolina
- **Start:** reset

## Why
Staff build a segment from the vocabulary the API returns. Membership is counted again when a contact's bookings change.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/segments`.
2. **New segment**. Name `E2E booked`. Rule text can repeat the sentence the builder will show. Kind **MARKETING**. Match **all**. Field **Lifecycle**, operator **eq**, value **BOOKED**. Save.
3. Read the new card's count. Open the list. Find **A. Fontaine**.
4. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**. Open **ANK-2026-0018**. Click `→ CANCELLED`. Reason `E2E SEG-02 cancel 0018`. `Record`.
5. Return to Segments and open the same card.

## Expected
- [ ] E1 · The saved card's count equals its list total. Fontaine is on the list. Do not hard-code the starting count.
- [ ] E2 · After the cancel, the count is one lower and Fontaine is absent.
- [ ] E3 · Toast on save is `Segment saved`. The cancel toast is `Status updated` (or the booking shows CANCELLED).

## Notes
Same cancel path as CRM-02, different reason. Do not edit the contact in the CRM.
