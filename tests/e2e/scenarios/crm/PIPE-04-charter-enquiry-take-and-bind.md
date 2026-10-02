# PIPE-04 · Charter enquiry opens an unassigned New lead; take it; bind it after a charter booking
- **Tags:** sprint-10, crm
- **Priority:** P2
- **Users:** Guest + Carolina
- **Start:** reset

## Why
A charter enquiry is a deal before it is a booking. Binding is a person, after the RMS has the charter.

## Steps
1. **Guest.** Submit a charter enquiry (OFF-04). Use a new email `e2e.pipe04@iconic.test`.
2. **Carolina.** Open `http://localhost:3001/crm/sales/pipeline`. Filter **Unassigned**. Open the new charter deal.
3. **Take** it.
4. In the RMS, create the charter booking for that contact (the existing charter create path). Return to the deal. **Bind to booking** and choose that booking.

## Expected
- [ ] E1 · The deal is **New lead**. The card shows type `CHARTER`, owner **Unassigned**, and **NO RMS RECORD YET**.
- [ ] E2 · After Take, the owner is Carolina M.
- [ ] E3 · After bind, the deal shows the booking reference and is no longer a free New lead. The stage follows that booking.

## Notes
Do not invent a charter total. If Take or Bind is missing for Admin, that is a **BUG**.
