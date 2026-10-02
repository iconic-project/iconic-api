# Sprint 20 — Booking engine and agency portal on stays

**Theme:** the public engine and the agency portal search by dates instead of picking a departure.

At the end of this sprint:
- the engine reads property and room type content instead of itineraries,
- guests search `check_in / check_out / guests / rooms`, see a price calendar, pick room types and plans, and check out with room-night holds,
- agencies see availability and rates by date range and request stays,
- the itinerary CMS has become property and room type content in the panel.

**Read first (whole sprint):** 09 H6, H7, H8, H20, H21, H22; `routes/api/engine.php`, `Services/Engine/*`, `Http/Controllers/Engine/*`, `Actions/Checkout/*`, `routes/api/portal.php`, `iconic-engine/app/pages/**`, `iconic-portal/app/pages/**`.

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Content: itineraries → property and room types](01-content.md) | api, panel |
| 02 | [Engine API: property, calendar, availability, quote](02-engine-api.md) | api |
| 03 | [Engine checkout on room-nights](03-engine-checkout.md) | api |
| 04 | [Engine frontend: search, results, details, confirmation](04-engine-frontend.md) | engine, ui |
| 05 | [Waitlist on room types and dates](05-waitlist.md) | api, engine |
| 06 | [Agency portal on stays](06-portal.md) | api, portal |
| 07 | [Sprint close: e2e and report](07-sprint-close.md) | api |

**E2E scenarios:** HENG-01 … HENG-07, HPOR-01 … HPOR-03 (new); `web/*` and `portal/*` scenarios rewritten or retired (state which).
