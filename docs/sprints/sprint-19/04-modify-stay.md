# 19-04 — Modify a stay and move a room

**Repo:** iconic-api
**Depends on:** 19-02
**Read first:** 09 H5, H12, HQ8; `Actions/Bookings/MoveBooking.php`, `Http/Requests/Rms/{Preview,}MoveBookingRequest.php`, `BookingController::movePreview/move`, `Support/Bookings/BookingCharges.php`, `BookingMutationLock.php`, business rule `modification_fee_usd`

## Why
Guests extend, shorten and shift stays. The yacht "move to another departure/cabin" becomes one general Action.

## Build
1. `ModifyStay(booking, StayDates $new, ?room_type, ?room_id, reason)` — one Action, one transaction, `BookingMutationLock`:
   - Compute `added = new − old` nights and `removed = old − new` nights.
   - Claim `added` on the current room if free; else, if `room_id`/`room_type` given or allowed, move the whole stay (allocator for the new stay, excluding nothing). Any night unavailable → 409, nothing changes.
   - Release `removed` nights.
   - Pricing (frozen at sale): keep original `night_lines` for nights still in the stay; **added** nights priced with the **current** rates version and the booking's plan, appended with `rates_version_id` per line; **removed** nights credited at their sold price; penalty on removed nights per the cancellation set at days-to-arrival (HQ8 default) unless reason code `GUEST_FRIENDLY` and permission `bookings.waive_penalty` (Admin, Manager).
   - LOS discount: recomputed on the new length **only for the added nights' contribution** — document the exact rule in the Action's docblock and in REPORT; if unclear, implement "LOS band of the new length applied to added nights only" and mark `TODO(OPEN: HQ8)`.
   - `modification_fee_usd` applied once per call when > 0.
   - Updates `check_in`, `check_out`, `nights`, `price_lines` (summary regenerated from `night_lines`), `total`, `tax_lines` (recomputed for changed nights only).
2. `MoveRoom(booking, room_id, reason)`: same dates, different room (same or other type). Different type → reprice? **No**: a move is operational unless `reprice: true` is passed, which delegates to `ModifyStay`. Upgrade = free move.
3. Preview endpoint: `POST /api/rms/bookings/{id}/modify/preview` → new night lines, credits, penalty, new total, balance impact; `POST …/modify` commits (same payload + reason). Old `move/preview` and `move` routes map to `MoveRoom` / `ModifyStay`.
4. Status guard: allowed while `REQUESTED…FULLY_PAID` and `IN_HOUSE` (extend in house); not after `CHECKED_OUT`, `NO_SHOW`, cancelled.
5. Payments: a higher total re-opens balance due (`FULLY_PAID` → `CONFIRMED` transition through the Action with history; this is a legal transition to add in `Transitions` with reason "stay modified"); a lower total below paid amount creates a refund request (existing refund flow), never an automatic refund.

## Tests
Extend at end, extend at start, shorten, shift by one day (overlap), move to another type, no availability → 409 and nothing changed, penalty bands, waiver permission, FULLY_PAID re-opened, refund request created, in-house extension, history entry with before/after stay.

## Done when
Extending an in-house guest by two nights on the same room is one API call and one history entry.
