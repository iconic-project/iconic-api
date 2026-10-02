# 20-03 — Engine checkout on room-nights

**Repo:** iconic-api
**Depends on:** 20-02
**Read first:** `Actions/Checkout/*`, `Engine/CheckoutController`, `Models/CheckoutSession.php`, `Enums/CheckoutPath.php`, `CheckoutSessionStatus.php`, `Listeners/ExpireWebCheckoutSession.php`, `ExpireStripeCheckoutsCommand.php`, business rules `holds.web_minutes`, `web_extension_minutes`, `discounts.online_deposit_discount_pct`

## Build
1. `POST /checkout` body: `quote_token` + guest/contact + consents. Re-validates the token, re-runs restrictions, allocator, quoter; on drift returns the existing price-changed response with the new quote.
2. Web hold: `ClaimKind::Hold`, `HoldType::Web`, expiry `holds.web_minutes`, extend once by `web_extension_minutes` (unchanged rules). One `CheckoutSession` per checkout, holding N rooms.
3. Both checkout paths keep working: **request** (book now, pay later → `CreateBookingRequest` on the stay) and **online deposit** (Stripe Checkout → on payment `PaymentSettled` converts holds to bookings). Deposit amount from the rate plan; non-refundable plans may require full payment online — only if the plan says `deposit_pct = 100`.
4. `checkout/{token}/status` shows the stay and rooms; `submit` converts.
5. Complete-reservation flow (`complete/{token}`): guests per room (count from party), billing, declarations — unchanged except labels and PNG removal.

## Tests
Port every checkout test: hold create/extend/expire, two parallel checkouts on the last room (one wins), price drift, Stripe settle converts all rooms, expiry releases nights and fires `AvailabilityChanged`.

## Done when
A guest can hold and pay a 3-night, 2-room stay online in test mode.
