# 18-03 — Rate plans

**Repo:** iconic-api
**Depends on:** 18-02
**Read first:** 09 H8, H10; `BusinessRulesDocument` `cancellation.bands`, `Services/Pricing/QuoteTerms.php`, `Models/Booking.php` (`deposit_pct`, `balance_days`, `balanceDueDate()`), `Support/Bookings/BookingCharges.php`

## Why
A hotel sells the same room under several conditions: flexible, non-refundable, with breakfast. The plan decides price adjustment, deposit, balance timing and cancellation.

## Build
1. Cancellation band sets: business rules `cancellation` becomes `{sets: {CODE: list<band>}}` keeping the existing `bands` as set `STANDARD` and `charter_bands` as set `CHARTER` (config migration, same values, `Sprint 18: cancellation sets (09 H8)`). Validation: every rate plan's `cancellation` code exists in `sets`.
2. `QuoteTerms` for stays: `deposit_pct`, `balance_days` (days before `check_in`), `refundable`, `cancellation_set` — all from the plan. `refundable = false` means the cancellation set is ignored and the penalty is 100% at every band; the panel shows "Non-refundable".
3. `RoomPricer` applies `adjust_pct` (step 7 of 18-02) and fills `deposit_pct` from the plan.
4. `RatePlanOptions` support class: lists plans for a room type and stay (all plans in v1; per-type plan availability is a later decision — leave `TODO(OPEN)` only if the client raises it).
5. Engine/agency visibility of a plan is out of scope here (Sprint 20).

## Tests
Plan adjust on a reference quote; non-refundable penalty; deposit from plan; missing cancellation set refused at publish; existing bookings' `deposit_pct`/`balance_days` unchanged after the config migration.

## Done when
A quote for the same stay under two plans differs only in the plan line, deposit and terms.
