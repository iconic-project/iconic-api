# CRM-02 · Cancel a confirmed booking → contact value and segment change
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Lifetime value and segment are derived from sold bookings. Cancelling the only sold booking on a contact must move the CRM row by itself. No CRM control writes money.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/contacts`. Find **A. Fontaine**. Read lifecycle, LTV and segment.
2. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**. Open **ANK-2026-0018** (A. Fontaine). Overview tab.
3. Click `→ CANCELLED`. In the reason modal, type `E2E CRM-02 cancel 0018`. `Record`.
4. Return to `http://localhost:3001/crm/sales/contacts` (refresh). Find **A. Fontaine**. Read lifecycle, LTV and segment.

## Expected
- [ ] E1 · Before cancel: Fontaine is **BOOKED** · **USD 26,600** · **HIGH**. ⚠ UNVERIFIED — task 06 browser.
- [ ] E2 · 0018 is CONFIRMED after reset (seed OVERDUE is stored CONFIRMED). Reason is required. Toast `Status updated` (or the panel shows CANCELLED). ⚠ UNVERIFIED — i18n `bookings.transitionedToast`.
- [ ] E3 · After refresh: Fontaine LTV `—` · segment **NEW** · lifecycle **MQL**. No CRM control changed the booking. ⚠ UNVERIFIED — task 06 browser (marketing consent stays, so MQL after the sold booking drops out).
- [ ] E4 · Other contacts on the list keep the values they had in CRM-01. Fontaine’s row is the only one that moved.

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("reference","ANK-2026-0018")->value("status")'` → `CANCELLED`.
- `bin/db-check.sh 'App\Models\Contact::query()->withDerived()->where("name","A. Fontaine")->first()'` → `lifetime_value` 0, `segment` `NEW`, `lifecycle` `MQL`. ⚠ UNVERIFIED — `ContactDerived` + task 06.

## Notes
0018 is the only sold booking on Fontaine. Do not cancel a group row. Do not edit the contact in the CRM.
