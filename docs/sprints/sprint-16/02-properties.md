# 16-02 — Properties: rename yachts

**Repo:** iconic-api
**Depends on:** 16-01
**Read first:** 09 §2, H6, H19, H20; `app/Models/Yacht.php`, `app/Policies/YachtPolicy.php`, `YachtController`, migration `2026_09_20_200013_create_yachts_table.php`, every `yacht_id` foreign key (`grep -rn yacht_id database/migrations`)

## Why
A property is what a yacht was: the container of the rooms. Renaming now keeps every later task in the new vocabulary.

## Build
1. **Migration** `…_rename_yachts_to_properties.php`:
   - `Schema::rename('yachts', 'properties')`.
   - Rename `yacht_id` → `property_id` on `cabins` and `departures` (drop FK, rename column, re-add FK to `properties`, keep `restrictOnDelete`). Keep the `departures` unique index, renamed to `(property_id, date)`.
   - Add nullable content columns (09 H20): `slug` (unique), `timezone` (nullable, null = business time zone, see H18), `address_line_1`, `address_line_2`, `city`, `postcode`, `country` (ISO-2), `phone`, `email`, `description` (text), `hero_image_path`, `hero_alt`, `highlights` (json), `facts` (json), `faqs` (json), `policies_text` (text), `meta_title` (60), `meta_description` (155), `status` (`ACTIVE`/`INACTIVE`, default `ACTIVE`).
2. Model `Property` (rename `Yacht`); `PropertyPolicy`; `Rms\PropertyController` with `index` + `show` + `update` (content only, Action `UpdatePropertyContent`, history `property.updated`). Routes `GET/PATCH /api/rms/properties…`. Keep `GET /api/rms/yachts` as a **deprecated alias** returning the same resource until Sprint 22 (note it in `REPORT.md`).
3. Permissions: add `properties.manage` to `App\Enums\Permission` (group "Inventory"); grant to Admin via a migration that updates the seeded role, the same way `grant_*` migrations do.
4. Morph map: if `Yacht::class` is in a morph map or stored as `holder_type` / history `subject_type`, add a migration that rewrites stored class names to `Property::class` (history rows: only the type column, never `what`/`why`).
5. Replace `Yacht` with `Property` across `app/` and `tests/`. Departure keeps working: `Departure::property()` replaces `Departure::yacht()`.
6. API resources: field `yacht` → `property` everywhere. Regenerate OpenAPI (Scramble) and note the breaking field rename for the panel/engine/portal in `REPORT.md`.

## Tests
- Migration test: a database seeded with the yacht fixture migrates; counts of properties = previous yachts; every cabin and departure points at a property.
- Feature tests for `properties` index/show/update: happy path, validation (slug unique, meta lengths), each role's permission.
- History entry `property.updated` written once per change, not on a no-op.

## Out of scope
Room types (task 03). Frontend label changes (they follow the regenerated types in later sprints).

## Done when
`grep -rni yacht app/ tests/ --include=*.php` returns only the deprecated alias route and its test.
