# Sprint 19 — Bookings on stays

**Theme:** a booking is a room for a stay. Departures stop being written.

At the end of this sprint:
- every booking (new and old) has `check_in`, `check_out`, `nights`, `property_id`, `room_type_id`, `room_id`,
- staff create, request, hold, confirm, move and modify **stays**; groups may mix dates,
- the front desk checks guests in and out at any time and records no-shows; no automation changes a stay status,
- every payment, cancellation and hold deadline is measured from the arrival date,
- the seed mode switches to `hotel`; the yacht adapter from Sprint 17 is deleted.

**Read first (whole sprint):** 09 H1–H3, H5, H10–H13, H19; `Models/Booking.php`, `Actions/Bookings/*`, `Support/Bookings/Transitions.php`, `Support/Operations/VoyageStatus.php`, `Actions/Payments/*`, `Support/Refunds/*`, `Support/Commissions/*`, `Support/BusinessHours.php`.

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Stay columns on bookings, backfill](01-booking-stay-columns.md) | api |
| 02 | [Create bookings, requests and groups on stays](02-create-on-stays.md) | api |
| 03 | [Check-in, check-out, no-show; night audit](03-check-in-out.md) | api |
| 04 | [Modify a stay and move a room](04-modify-stay.md) | api |
| 05 | [Money clock on arrival: deposits, balance, overdue, cancellation, refunds, commissions](05-money-clock.md) | api |
| 06 | [Agency holds and the request queue on stays](06-holds-and-queue.md) | api |
| 07 | [Panel: bookings and front desk](07-panel-bookings-front-desk.md) | panel |
| 08 | [Switch seed mode, delete the adapter, sprint close](08-switch-and-close.md) | api |

**E2E scenarios:** HBKG-01 … HBKG-10 (new). BKG-01 through BKG-12 are retired (yacht cabin and departure steps).
