# HSET-01 · Properties and room types in the RMS
- **Tags:** sprint-16, hotel, inventory
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A property, a room type, and a room are the inventory staff edit. Each change writes one history line. An empty room type can be deactivated.

## Steps
1. The seed is Hotel Demo. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms`.
2. In the sidebar, under **Booking engine**, open **Property**. The seeded property is **Hotel Demo**.
3. Set **Description** to `E2E property description`. Click **Save**.
4. Open **Room types**. Click **Add room type**. Code `E2E`, name `E2E Garden`, base occupancy `2`, max occupancy `2`, max adults `2`, max children `0`. Click **Add room type**.
5. On that room type, set **Code** `E1` and **Label** `E2E 1`. Click **Add room**.
6. Close the drawer. Add a second room type with no rooms: code `EMP`, name `E2E Empty`, same occupancy numbers. Open `EMP`. Click **Deactivate**.
7. Open **History** on the property, on `E2E Garden`, on `E2E Empty`, and on room `E1`.

## Expected
- [ ] E1 · Description is `E2E property description`. History has one `property.updated` for that description, and no second line for the same save.
- [ ] E2 · Room type `E2E` / `E2E Garden` is active. History has one `room_type.created` for it.
- [ ] E3 · Room `E1` / `E2E 1` belongs to `E2E Garden`. History has one `room.created` for it.
- [ ] E4 · `E2E Empty` is inactive. History has one `room_type.deactivated` and the line includes `status: ACTIVE → INACTIVE`. No second deactivate line. `E2E Garden` is still active. The property meter reads `100% COMPLETE`.

## Cross-checks
- `bin/db-check.sh 'App\Models\Property::query()->where("code", "HTL")->value("description")'` → `"E2E property description"`
- `bin/db-check.sh 'App\Models\RoomType::query()->whereIn("code", ["E2E", "EMP"])->orderBy("code")->get(["code", "status"])'` → `E2E` active, `EMP` inactive
- `bin/db-check.sh 'App\Models\Room::query()->where("code", "E1")->exists()'` → `true`

## Notes
Sprint 20 task 01 puts **Property** and **Room types** in the Booking engine menu. The seeded property is Hotel Demo, not ANAMARA. Itineraries are read-only and the old itinerary route redirects to Room types.
