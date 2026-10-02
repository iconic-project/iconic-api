# 17-05 — Internal blocks over date ranges

**Repo:** iconic-api
**Depends on:** 17-03
**Read first:** 09 H4, H7; `Actions/Blocks/*`, `Models/InternalBlock.php`, `Enums/BlockReason.php`, `Support/Blocks/ScopeSummary.php`, `ConflictMessage.php`, `InternalBlockPolicy`

## Why
A hotel blocks a room for maintenance from Tuesday to Friday, or ten rooms for a negotiation over a week — not "these cabins on this departure".

## Build
1. Migration: `internal_blocks` + `property_id`, `starts_on`, `ends_on` (exclusive, like `check_out`). Backfill from the departure each existing block was on.
2. `CreateBlock` accepts `{starts_on, ends_on, rooms: [room ids] | room_type + count, reason, notes}`. Uses `claim` / `claimType` with `ClaimKind::Block`. Blocks ignore min/max stay (17-03 point 3) and restrictions.
3. `ReleaseBlock` keeps the mandatory `release_note`; new `ShortenBlock` (release the trailing or leading nights; reason mandatory) — one history entry `block.shortened` with old and new range.
4. `BlockReason`: keep `MAINTENANCE`, `COURTESY`, `NEGOTIATION_HOLD`; `FAM_TRIP` stays (agencies still do fam stays). Add `OUT_OF_ORDER` only if 09 is amended — otherwise use `MAINTENANCE` (do not invent enums).
5. `ConflictMessage`: "Room 204 is sold on Wed 4 Mar 2028 (ANK-2028-0012)" — first conflicting night and holder.
6. `ScopeSummary`: describe a block as "Rooms 101, 102 · Tue 3 – Fri 6 Mar 2028 · 3 nights".

## Tests
Create (by rooms, by type+count), conflict message, release, shorten, each role, history once per change.

## Done when
The Blocks page API returns ranges, not departures; no block code references `Departure`.
