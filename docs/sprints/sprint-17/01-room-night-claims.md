# 17-01 — Room-night claims table and backfill

**Repo:** iconic-api
**Depends on:** Sprint 16
**Read first:** 09 H1, H4, H19; migration `2026_09_20_200017_create_cabin_claims_table.php`; `app/Models/CabinClaim.php`; `Enums/ClaimKind`, `HoldType`, `ReleaseReason`

## Why
The unique key `(departure, cabin)` was the whole double-booking guarantee. The same guarantee must hold for `(room, night)`.

## Build
1. **Migration** `…_create_room_night_claims_table.php`:
   - Columns per 09 H4: `room_id` FK (restrict), `night` (date), `morphs('holder')`, `kind` (16), `hold_type` (16, nullable), `expires_at`, `released_at`, `release_reason` (16, nullable), `claim_group` (uuid, see 3), timestamps, audit columns.
   - `active_key` stored generated: `if(released_at is null, concat(room_id,'-',night), null)` **unique**.
   - Indexes: `(night, released_at)`, `(room_id, night)`, `(holder_type, holder_id, released_at)`, `(kind, expires_at)`, `claim_group`.
   - Trigger `room_night_claims_prevent_delete` (same as `cabin_claims`).
2. Model `RoomNightClaim` (casts `night` with `CalendarDate`). Immutable except the release columns: `save()` on an existing row may change only `released_at`, `release_reason`, `updated_*` — enforce in the model, throw otherwise.
3. `claim_group`: every call that claims a stay writes the same uuid on all its nights, so a stay's nights can be released/converted together and shown as one bar.
4. **Backfill migration** `…_backfill_room_night_claims.php`: for every `cabin_claims` row, insert one row per night of `departure->stayDates()` with identical holder/kind/hold_type/expires_at/released_at/release_reason/audit, one `claim_group` per source row. Chunked (500), idempotent (skip if a group already exists for that source: store `legacy_cabin_claim_id` nullable column, unique).
5. From this task on, `cabin_claims` is **frozen**: add a trigger that refuses inserts, and a note in `REPORT.md`. Yacht code writes nights through the adapter in 17-03.

> Order matters: merge 17-01 together with 17-03 or put the insert-refusing trigger in 17-03. Pick one and say which in the report.

## Tests
- Backfill: yacht fixture → nights = Σ (claims × nights of their departure); active claims stay active; `active_key` unique holds.
- Inserting a second active claim on the same `(room, night)` throws `UniqueConstraintViolationException`.
- A released claim and a new active claim on the same `(room, night)` coexist.
- Delete is refused by the database.

## Done when
Every active cabin claim has exactly `nights` active room-night rows; `composer check` green.
