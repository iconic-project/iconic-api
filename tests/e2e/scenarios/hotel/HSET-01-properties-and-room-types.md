# HSET-01 · Properties and room types in the RMS
- **Tags:** sprint-16, hotel, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A property, a room type, and a room are the inventory staff edit. Each change writes one history line. An empty room type can be deactivated.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms`.
2. In the sidebar, under **Booking engine**, open **Property**. The seeded property is **ANAMARA**.
3. Set **Description** to `E2E property description`. Save.
4. Open **Room types**. Add a room type: code `E2E`, name `E2E Garden`, base occupancy `2`, max occupancy `2`, max adults `2`, max children `0`.
5. On that room type, add a room: code `E1`, label `E2E 1`.
6. Add a second room type with no rooms: code `EMP`, name `E2E Empty`, same occupancy numbers. Deactivate `EMP`.
7. Read history on the property and on both room types.

## Expected
- [ ] E1 · Description is `E2E property description`. History has one `property.updated` for that description, and no second line for the same save.
- [ ] E2 · Room type `E2E` / `E2E Garden` is active. History has one `room_type.created` for it.
- [ ] E3 · Room `E1` / `E2E 1` belongs to `E2E Garden`. History has one `room.created` for it.
- [ ] E4 · `E2E Empty` is inactive. History has one `room_type.deactivated` (`active` → `inactive`) and no second deactivate line. `E2E Garden` is still active.

## Cross-checks
- `bin/db-check.sh 'App\Models\Property::query()->where("code", "ANAMARA")->value("description")'` → `"E2E property description"`
- `bin/db-check.sh 'App\Models\RoomType::query()->whereIn("code", ["E2E", "EMP"])->orderBy("code")->get(["code", "status"])'` → `E2E` active, `EMP` inactive
- `bin/db-check.sh 'App\Models\Room::query()->where("code", "E1")->exists()'` → `true`

## Notes
Sprint 16 ships the API (`/api/rms/properties`, room types, rooms) and no panel page. Sprint 20 task 01 adds Booking engine → **Property** and **Room types**. Until that menu exists, stop at step 2 and record the miss. Do not invent a click path on Yacht Layout or Itineraries. Tasks 02 and 03 left this file for task 08.
