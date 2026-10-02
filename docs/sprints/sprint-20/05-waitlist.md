# 20-05 — Waitlist on room types and dates

**Repo:** iconic-api, iconic-engine
**Depends on:** 20-02
**Read first:** 09 H21; `Models/WaitlistEntry.php`, `Actions/Waitlist/*`, `Support/Waitlist/*`, `Listeners/OfferWaitlistCabins.php`, `WaitlistNotifyCommand.php`, `Engine/WaitlistController`

## Build
1. Migration: `waitlist_entries` + `room_type_id`, `check_in`, `check_out`; backfill from departure + category (category → the room type created in 16-03). `departure_id`, `cabin_category` nullable (dropped in 22).
2. Engine: when a room type is `SOLD_OUT` for a stay and `room_types.waitlist_enabled`, offer "Join the waitlist" with the stay prefilled.
3. `OfferWaitlistRooms` (rename listener): on `AvailabilityChanged`, find active entries whose **whole stay** is now bookable for their party (allocator + restrictions) and notify staff / guest per the existing rules. Copy: "A Suite is free — Thu 5 – Sun 8 Mar 2028".
4. Panel waitlist lists show stays.

## Tests
Backfill; partial overlap does not trigger; full availability triggers once; copy.

## Done when
No waitlist code references a departure.
