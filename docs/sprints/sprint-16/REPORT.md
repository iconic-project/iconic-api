# Sprint 16 · Report
Each task appends its section below.

## Task 01 · Adopt the hotel decisions and amend the rules

### What was built
`09-hotel-generalisation.md` was already in `docs/requirements/`. It is now listed in `docs/requirements/INDEX.md`, and it ranks above `08` for stays, rooms and nights.

`iconic-core.mdc` now describes a hotel reservation system (rooms sold by the night). The order of authority puts `09` first, scoped as in that document's header. Rule 5 is the stay interval `[check_in, check_out)`. Rule 9 also forbids automation from changing a stay status (09 H11).

`laravel.mdc` names check-in, check-out, nights and date of birth as `CalendarDate` fields, says an expired hold frees room-nights, points tests at `hotel-seed-data.json` (`reference_quotes`) while keeping the yacht price list until Sprint 18, and quotes the §2 naming rule.

`README.md`, `AGENTS.md` and `CLAUDE.md` open with "Laravel 13 API for Iconic (hotel reservations)" and link `docs/sprints/HOTEL-ROADMAP.md`.

### Files touched
- `.cursor/rules/iconic-core.mdc`
- `.cursor/rules/laravel.mdc`
- `README.md`
- `AGENTS.md`
- `CLAUDE.md`
- `docs/requirements/INDEX.md` (restored, then the 09 line added)
- `docs/requirements/09-hotel-generalisation.md` (already present, not edited)
- `docs/sprints/sprint-16/REPORT.md`

### Deviations
`docs/requirements/INDEX.md` was not on disk. Commit `4e42d8c` deleted it with the rest of `docs/requirements/` (except the brand book). Restored the index from the parent of that commit, added the 09 line, and wrote Iconic where the old index said Anakata (`iconic.co`, "Iconic's decisions"). The later rename commit never saw this file.

`08` in the index is no longer "highest authority" without qualification. The 09 line would otherwise contradict it.

`AGENTS.md` and `CLAUDE.md` had no "Requirements and sprints" heading. Added one so the roadmap link has the place the task names.

### Open questions
None.

### Notes for later
Sibling copies of `iconic-core.mdc` in `iconic-ui`, `iconic-panel`, `iconic-engine` and `iconic-portal` still say departures are Sundays and still name yachts ANAMARA and ANATIVA. This workspace applies every copy. Sync them or agents in those repos still see the yacht rule. This task's repo line is iconic-api only.

`01`–`08`, the prototypes, the screenshots, `docs/requirements/README.md` and sprints 00–15 (including `docs/sprints/ROADMAP.md`) are still absent from the working tree after `4e42d8c`. `examples/seed-data.json` and `examples/booking-engine-feed.json` came back in `f553447`. Restoring the rest is outside this task. Later tasks that say "read 08" cannot, until those files return.

`docs/requirements/examples/hotel-seed-data.json` does not exist yet. Sprint 16 task 06 adds it. The tests bullet in `laravel.mdc` already points at it.
