# PRIV-04 · Erasure waits for the return date; a past-only contact can be erased
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
An upcoming cruise blocks erasure. A contact with no future return can be anonymised. Documents and payments stay.

## Steps
1. Sign in as Carolina. Open Consent & Data Rights. **New request**: type **Erasure**, contact **Harrison & Whitfield** (ANK-2026-0003, return still ahead), channel **Email**.
2. Verify, type the contact email, **Erase**.
3. **New request**: type **Erasure**, contact **Anna Whitfield** (no booking), channel **Email**. Verify, type her email, **Erase**.
4. Open Contacts. Search the erased name. Open a booking that contact never had — payments and documents for ANK-2026-0003 are unchanged.

## Expected
- [ ] E1 · Harrison is refused. The message is `This contact has a booking whose return date has not passed.` The name is unchanged.
- [ ] E2 · Anna completes. Contacts shows `Erased contact #{id}` with her id. The outcome names the passport retention in months from the live rule (seed 24).
- [ ] E3 · ANK-2026-0003 still has its payments and documents.

## Notes
Do not erase a contact who still has a booking. Task 09 saw this on a non-reset database for contact 19; on a reset Anna Whitfield is the no-booking row. ⚠ UNVERIFIED — confirm she still has no booking after reset.
