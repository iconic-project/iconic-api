# 19-05 — Money clock on arrival: deposits, balance, overdue, cancellation, refunds, commissions

**Repo:** iconic-api
**Depends on:** 19-01, 19-03
**Read first:** 09 H10; `Models/Booking.php` (`balanceDueDate`, hold helpers ~L490–550), `Actions/Bookings/DecideOverdue.php`, `Console/Commands/FlagOverdueCommand.php`, `Support/Refunds/*`, `Support/Commissions/*`, `CommissionScanCommand.php`, `Support/Agencies/AgencyBookingWindow.php`, business rules `commission`, `payments`, `cancellation`

## Why
Every deadline that mattered for a cruise still matters for a stay, measured from arrival or check-out. This task finds and converts each one.

## Build
1. Inventory every use: `grep -rn "departure->date\|returnDate()\|departure_id" app/Support/{Payments,Refunds,Commissions,Agencies,Bookings} app/Actions/{Payments,Refunds,Commissions,Bookings} app/Console`. List them in REPORT before changing them.
2. Replace with `StayClock` / `$booking->stay()` per the 09 H10 table:
   - balance due = `check_in − balance_days` (plan's `balance_days`, frozen on the booking); balance reminders `payments.balance_reminder_days` before that.
   - `FlagOverdue` / `DecideOverdue` measured against balance due (unchanged logic, new date source).
   - Cancellation penalty band = days from cancellation date to `check_in`, band set from the booking's rate plan (legacy bookings: `STANDARD` or `CHARTER`).
   - Commission payable = `check_out + commission.payable_days_after_check_out` — config migration renames `payable_days_after_cruise` (same value). Commission accrues only for `CHECKED_OUT` (and `NO_SHOW` if the no-show charge is collected — `TODO(OPEN)` if the business rules doc is silent).
   - `AgencyBookingWindow` uses `check_in`.
3. Short-lead bookings: when `check_in − balance_days` is already past at creation, the whole amount is due on creation + `payments.wire_window_hours` (same as today's behaviour for near departures — confirm by test, do not change).
4. Pay-at-hotel: a rate plan with `deposit_pct = 0` and `balance_days = 0` means nothing is due before arrival; balance due = `check_in`; overdue is never flagged before check-out. Make sure `FlagOverdue` respects this (add test).

## Tests
One test per converted rule with a stay arriving on a Thursday; regression tests for the backfilled yacht fixture (values identical); pay-at-hotel plan never flagged overdue before arrival.

## Done when
`grep` in point 1 returns nothing outside legacy-read code.
