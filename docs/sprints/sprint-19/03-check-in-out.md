# 19-03 — Check-in, check-out, no-show; night audit

**Repo:** iconic-api
**Depends on:** 19-01
**Read first:** 09 H3, H11, HQ3, HQ7; `Support/Bookings/Transitions.php`, `Actions/Bookings/TransitionBooking.php`, `Support/Operations/VoyageStatus.php`, `Console/Commands/VoyageStatusCommand.php`, `Support/Schedule/IconicSchedule.php`, `Listeners/RaiseAlertsOnBookingStatusChanged.php`

## Why
On a yacht everyone boards at once and the system moved statuses by date. In a hotel each guest arrives at their own moment, any time on or after the arrival day; the front desk is the source of truth.

## Build
1. `Transitions`: replace the `OnBoard`/`Completed` date guards. `IN_HOUSE`, `CHECKED_OUT`, `NO_SHOW` are **not** reachable through the generic transition endpoint — only through the dedicated Actions below (return 422 with a pointer to the right endpoint).
2. Actions (permission `bookings.front_desk`, new; grant Admin, Manager, Sales Exec by migration — confirm Sales Exec with the client, `TODO(OPEN)` if unsure):
   - `CheckInBooking(booking, ?room_id, ?at)`: guards `StayClock::isArrivalDayOrLater`, payment rule (`FULLY_PAID`, or `CONFIRMED` when `stay.check_in_requires_full_payment = false`). `at` defaults to now; a back-dated `at` must be ≥ arrival date 00:00 local and ≤ now (late data entry). Optional `room_id` change → claims moved within the transaction (one history entry `booking.checked_in` noting the room change). Sets `checked_in_at`, status `IN_HOUSE`.
   - `CheckOutBooking(booking, ?at)`: from `IN_HOUSE` only. If `today < check_out` it is an **early departure**: delegates the unused nights to `ModifyStay` (19-04) in the same transaction, reason mandatory. Sets `checked_out_at`, status `CHECKED_OUT`. Late check-out (after `stay.check_out_time` on the check-out date) is recorded, not charged, unless an extra exists.
   - `MarkNoShow(booking, reason)`: guards per 09 H11. Releases nights from `check_in + 1` (HQ7 default) via `ClaimService::release(..., $nights)`. Computes the no-show charge from the rate plan's cancellation set at 0 days and records it as a charge line (`BookingCharges`) — never a payment. Status `NO_SHOW`.
   - `UndoCheckIn` (Admin only, reason mandatory, same day only) — mistakes happen at a desk.
3. `VoyageStatus` → `NightAudit` (`iconic:night-audit`, scheduled daily at `stay.no_show_cutoff_time` + a small offset, via `IconicSchedule`): raises alerts/CRM tasks only — "arrivals not checked in", "in-house past check-out", "departures today not checked out". **Changes no status** (H11). Delete `iconic:voyage-status` from the schedule; keep the command name as an alias that prints a deprecation line until Sprint 22.
4. Events: `BookingStatusChanged` keeps firing for the three new transitions; listeners updated (journeys, alerts, documents) to the new status names.

## Tests
Each Action: happy path, date guard (day before arrival refused; arrival day 00:01 allowed), payment guard both settings, back-dated time bounds, room change on check-in, early departure credit, no-show nights released, permissions, history once. Night audit creates alerts and changes no status (assert status counts before/after).

## Done when
A guest arriving at 02:00 on their arrival day can be checked in; nothing in the schedule writes `bookings.status`.
