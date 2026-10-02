# 19-02 — Create bookings, requests and groups on stays

**Repo:** iconic-api
**Depends on:** 19-01
**Read first:** 09 H5, H7, H8, H12, H13; `Actions/Bookings/CreateReservation.php`, `CreateBookingRequest.php`, `Support/Bookings/RequestParty.php`, `BookingFormOptions.php`, `Http/Requests/Rms/StoreReservationRequest.php`, `QuoteReservationRequest.php`, `Models/Group.php`

## Why
The single write path for new reservations moves to stays.

## Build
1. Request shape (RMS): `{check_in, check_out, contact, main_channel, channel_of_origin, rooms: [{room_type, room_id?, adults, child_ages, rate_plan}], expected_arrival_time?, override_restrictions?, override_reason?, …existing fields}`. `ValidatesStay` trait from 16-04. `rooms` 1…`stay.max_rooms_per_booking`. `room_id` optional (staff may choose; otherwise allocator).
2. One room → one booking; several rooms → one `Group` + N bookings, one transaction, one history entry per booking plus `group.created`. Bookings in a group may later be modified independently (H13). Different dates per room at creation: allowed via `rooms[i].check_in/check_out` overriding the top-level stay (optional fields).
3. For each room: `Restrictions::evaluate` (refuse with all reasons unless `override_restrictions` + permission + reason); `StayQuoter` (frozen `price_lines`, `night_lines`, `tax_lines`, `rates_version_id`, `deposit_pct`, `balance_days` from the plan); `ClaimService::claim` / `claimType` with the right `ClaimKind`. Price drift between the shown quote and commit → existing `PriceChangedException` behaviour.
4. `CreateBookingRequest` (engine/portal requests and staff "request" path): same stay input; hold expiry uses `BusinessHours::holdExpiry($submittedAt, $stay->checkIn(), $rules)` (near-term vs long-lead by days to arrival, H10).
5. `BookingFormOptions`: room types with occupancy limits, rate plans, restrictions summary for a stay (endpoint `GET /api/rms/bookings/form-options?check_in&check_out`).
6. Remove the yacht create path from controllers (the adapter is now unused by creation).

## Tests
Feature tests per path (one room, group of three with mixed dates, explicit room_id, allocator choice), restriction refusal and override (permission + reason), occupancy refusal, price drift 409, each role, own-records rule, history entries, `assertNoSensitiveFields` unaffected.

## Done when
Creating a 2-night stay starting on a Wednesday works end to end through the API.
