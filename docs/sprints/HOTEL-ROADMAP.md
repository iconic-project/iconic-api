# Hotel generalisation roadmap (sprints 16–22)

Goal: turn Iconic from a cabin-on-a-departure system into a room-for-a-range-of-nights system, without losing the architecture (Actions, single write path, history, config documents, frozen prices, holds, payments, CRM).

**Read first, always:** [`../requirements/09-hotel-generalisation.md`](../requirements/09-hotel-generalisation.md). It ranks above `08-dev-decisions.md` for everything about stays, rooms, nights and the retirement of departures.

> Numbering assumes Sprint 15 is the last yacht sprint. If Sprint 15 is still open, finish it first; these sprints assume a green `composer check` at the start.

## Strategy: expand → migrate → contract (09 H19)

The app stays shippable after every task. Old and new models live side by side until Sprint 22.

| Phase | Sprints | What happens |
|---|---|---|
| Expand | 16, 17, 19 | New tables and columns are added and **backfilled** from yacht data. Nothing old is dropped. |
| Migrate | 17–21 | Each domain switches its code, tests and UI to the new model, one task at a time. |
| Contract | 22 | Departures, itineraries, cabin claims and every yacht-only enum/key are removed. An Arch test forbids the old vocabulary. |

## Sprints

| Sprint | Theme | Outcome | Repos |
|---|---|---|---|
| [16](sprint-16/README.md) | Foundations | Rules amended, properties / room types / rooms, `StayDates`, stay business rules, hotel fixture | api, ui |
| [17](sprint-17/README.md) | Night inventory | Room-night claims, allocator, new `ClaimService`, availability grid, blocks and restrictions by date, panel calendar | api, panel |
| [18](sprint-18/README.md) | Nightly rates | Rates document v2, `RoomPricer`, rate plans, taxes and fees, rates editor | api, panel |
| [19](sprint-19/README.md) | Bookings on stays | Bookings carry stay dates, check-in/out/no-show, modify stay, payment clock on arrival, front desk | api, panel |
| [20](sprint-20/README.md) | Engine and portal | Date-range search, calendar, checkout on nights, room type content, agency portal | api, engine, portal, ui |
| [21](sprint-21/README.md) | Operations and CRM | Documents, registration export, guest experience, alerts, CRM derived data, reports (occupancy, ADR, RevPAR) | api, panel |
| [22](sprint-22/README.md) | Contract and cleanup | Offers by stay window, buyout decision, drop departures, vocabulary Arch test, docs | all |

## Dependency graph

```
16 ──► 17 ──► 18 ──► 19 ──► 20 ──► 22
                       └──► 21 ──┘
```
20 and 21 may run in parallel once 19 is done.

## Rules that do not change

Everything in `.cursor/rules/iconic-core.mdc` and `laravel.mdc` except core rule 5, which Sprint 16 task 01 rewrites. In particular: one Action per mutation, history in the Action's transaction, config through `ConfigPublisher` / `CurrentConfig`, money as USD integers, no automation cancels a booking (now also: no automation changes a stay status, 09 H11).

## How each task file is laid out

`Repo` · `Depends on` · `Read first` · `Why` · `Build` · `Data migration` (if any) · `Tests` · `Out of scope` · `Done when`. Finish every task by appending to the sprint's `REPORT.md`, as `iconic-core.mdc` "How to work a task" describes.
