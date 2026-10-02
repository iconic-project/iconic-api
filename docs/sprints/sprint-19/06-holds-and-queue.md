# 19-06 — Agency holds and the request queue on stays

**Repo:** iconic-api
**Depends on:** 19-02
**Read first:** `Enums/HoldType.php`, `HoldRule.php`, `Support/HoldExpiry.php`, `Support/Bookings/RequestQueueRules.php`, `RequestSummary.php`, `Actions/Bookings/TransitionBooking.php` (REQUESTED → CONFIRMED path), `ExpireHoldCommand.php`, `ReleaseExpiredHoldsCommand.php`, `Listeners/OfferWaitlistCabins.php`

## Build
1. `ON_HOLD_AGENCY` and request holds claim room-nights (they already go through `ClaimService` since 17-03 — remove the adapter calls).
2. Request queue (`booking-requests` page API): columns show stay, nights, room type, rooms count instead of departure/cabins; SLA unchanged; sorting by `check_in`.
3. `RequestSummary` copy: "2 rooms · Thu 5 – Sun 8 Mar 2028 · 3 nights".
4. Confirming a request whose hold expired: re-run allocator + restrictions; if the original room is gone but another room of the type is free for all nights, offer it in the confirm preview (no silent swap).
5. Expired hold release keeps the in-transaction `HoldExpired` contract.

## Tests
Agency hold create/expire/convert on a stay; confirm after expiry with an alternative room; queue ordering; SLA unchanged.

## Done when
The request queue never mentions a departure.
