# Sprint 16 — Foundations for hotels

**Theme:** lay the ground the rest of the migration stands on, without changing behaviour yet.

At the end of this sprint the app still sells yacht cabins exactly as before, but:
- the project rules no longer say "departures are Sundays",
- yachts and cabins are stored as **properties** and **rooms**, with **room types** as data,
- a `StayDates` value object and the stay business rules exist,
- a hotel fixture exists for every later test.

**Read first (whole sprint):** `docs/requirements/09-hotel-generalisation.md` (H1–H6, H18, H19), `docs/sprints/HOTEL-ROADMAP.md`, `.cursor/rules/*.mdc`.

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Adopt the hotel decisions and amend the rules](01-adopt-decisions-and-rules.md) | api (docs, rules) |
| 02 | [Properties: rename yachts](02-properties.md) | api |
| 03 | [Room types as data, rooms from cabins](03-room-types-and-rooms.md) | api |
| 04 | [`StayDates` and the stay clock](04-stay-dates.md) | api |
| 05 | [Stay business rules](05-stay-business-rules.md) | api |
| 06 | [Hotel fixture and seeders](06-hotel-fixture.md) | api |
| 07 | [Shared UI: stay types and date-range input](07-ui-stay-primitives.md) | ui |
| 08 | [Sprint close: e2e and report](08-sprint-close.md) | api |

**E2E scenarios:** HSET-01, HSET-02 (new); INV-01, INV-12 updated for the "Property / Room" labels.

## Definition of done for the sprint
- `composer check` green in Docker; `pnpm lint && pnpm typecheck && pnpm test` green in `iconic-ui`.
- No behaviour change visible to a yacht user except labels (Yacht → Property, Cabin → Room) in API resources that task 02/03 explicitly rename.
- `REPORT.md` has one section per task.
