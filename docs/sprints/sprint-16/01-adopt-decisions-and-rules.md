# 16-01 — Adopt the hotel decisions and amend the rules

**Repo:** iconic-api (docs and `.cursor/rules` only — no PHP)
**Depends on:** —
**Read first:** `docs/requirements/09-hotel-generalisation.md` (all), `.cursor/rules/iconic-core.mdc`, `.cursor/rules/laravel.mdc`, `docs/requirements/INDEX.md`, `docs/requirements/08-dev-decisions.md`

## Why
Every agent obeys the rule files. Core rule 5 ("Departures are Sundays; cruises are 7 nights") will make agents reject the new model. The rules must say what is now true before any code moves.

## Build
1. Place `09-hotel-generalisation.md` in `docs/requirements/` (if not already there) and add it to `docs/requirements/INDEX.md` with one line: what it is and that it ranks above `08` for stays, rooms and nights.
2. `iconic-core.mdc`:
   - "What we are building": a reservation system for **hotels** (rooms sold by the night). Keep the RMS / engine / CRM description. Remove "yachts ANAMARA and ANATIVA".
   - "Order of authority": insert `09-hotel-generalisation.md` at the top, scoped as in its header.
   - Replace rule 5 with: *Dates are ISO `YYYY-MM-DD`. A stay is `[check_in, check_out)`; a night `D` is the night from `D` into `D+1`. Any arrival day, any length allowed by the restrictions. Check-in/out times are operational, never inventory (09 H1–H3).*
   - Rule 9: append *No automation changes a stay status (09 H11).*
3. `laravel.mdc`:
   - Dates: "Date-only fields (check-in, check-out, nights, date of birth) use `CalendarDate`."
   - History exception paragraph: "cabin" → "room-nights" (keep the meaning).
   - Tests: replace the doc-02 pricing reference list with "use the reference stays in `docs/requirements/examples/hotel-seed-data.json` (`reference_quotes`)". Keep the old list until Sprint 18 lands, marked `(yacht, removed in Sprint 18)`.
   - Add a "Vocabulary" section quoting the naming rule from 09 §2.
4. `README.md`, `AGENTS.md`, `CLAUDE.md`: first line becomes "Laravel 13 API for Iconic (hotel reservations)". Add a link to `HOTEL-ROADMAP.md` under "Requirements and sprints".
5. Create `docs/sprints/sprint-16/REPORT.md` with the header the other sprints use.

## Out of scope
Any PHP change. Rewriting `01`–`07` (09 overrides them; do not edit them).

## Done when
- A reader of `iconic-core.mdc` alone would not think departures exist.
- `git diff --stat` shows only Markdown / `.mdc` files.
