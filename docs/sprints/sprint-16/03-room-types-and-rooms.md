# 16-03 — Room types as data, rooms from cabins

**Repo:** iconic-api
**Depends on:** 16-02
**Read first:** 09 H6, H7 (waitlist flag), H20; `app/Enums/CabinCategory.php`, `app/Models/Cabin.php`, `Support/Departures/SeedMapper.php`, `Services/Engine/EngineCabins.php`, `engine_settings.guests`

## Why
A yacht had two hard-coded categories. A hotel defines its own room types and their occupancy limits. Core rule 3: rules are data, not code.

## Build
1. **Migration** `…_create_room_types_table.php`: `room_types` per 09 §4 — `property_id` FK, `code` (16, unique per property), `name`, `base_occupancy`, `max_occupancy`, `max_adults`, `max_children`, `waitlist_enabled` (bool, default true), `sort`, `status` (`ACTIVE`/`INACTIVE`), content: `slug` (unique), `description`, `size_sqm` (nullable int), `bed_setup` (string), `amenities` (json), `photos` (json list of `{path, alt}`), `meta_title`, `meta_description`; audit columns.
2. **Migration** `…_rename_cabins_to_rooms.php`:
   - `Schema::rename('cabins', 'rooms')`; `rooms.property_id` already exists after 16-02.
   - Add `room_type_id` (nullable first), `floor` (nullable string), `status` (`ACTIVE`/`INACTIVE`, default `ACTIVE`).
   - **Backfill:** for each property, create one room type per distinct `category` value found (`SUITE` → code `SUITE`, name "Suite"; `OWNER` → code `OWNER`, name "Owner's Suite"), occupancy from current `engine_settings.guests.max_per_cabin` (read via `CurrentConfig`, never a literal). Set `rooms.room_type_id`. Then make it `NOT NULL`.
   - Keep `rooms.category` for now (dropped in Sprint 22); stop writing it.
   - Rename FK columns `cabin_id` → `room_id` on `cabin_claims` and `bookings` (drop/rename/re-add FK). Rename the `cabin_claims.active_key` expression accordingly (it is a generated column — recreate it).
3. Models `RoomType` and `Room` (rename `Cabin`). `Room::roomType()`. `RoomType::rooms()`. Enum `RoomTypeStatus`, `RoomStatus`.
4. Replace every read of `$cabin->category` / `CabinCategory` used for **decisions** with `$room->roomType` (code or id). Leave `CabinCategory` in place for code paths that still price by category (pricing moves in Sprint 18) and mark each remaining use `// TODO(Sprint 18): room type pricing (09 H8)`.
5. `Rms\RoomTypeController`: index, store, update, deactivate (Actions `CreateRoomType`, `UpdateRoomType`, `DeactivateRoomType`; history `room_type.*`). Deactivate refuses (409) while any future active claim exists for its rooms. `Rms\RoomController`: index, store, update (code, label, floor, room type, sort), deactivate with the same guard. Permission `properties.manage`.
6. Validation: `base_occupancy ≤ max_occupancy`, `max_adults ≤ max_occupancy`, `max_children ≤ max_occupancy`, `max_adults ≥ 1`.

## Tests
- Backfill test from the yacht fixture: 2 room types per property, every room typed, counts match.
- Unit tests for the occupancy validation matrix.
- Feature tests for room types and rooms (happy path, validation, roles, deactivate guard).
- Existing inventory/booking tests still pass (they now read `room_id`).

## Out of scope
Claims by night (Sprint 17). Pricing by room type (Sprint 18).

## Done when
No code path decides anything from `rooms.category`; only the pricing path still uses `CabinCategory`, every use marked with the Sprint 18 TODO.
