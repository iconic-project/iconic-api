# Sprint 18 — Nightly rates

**Theme:** price a room per night instead of a person per departure.

At the end of this sprint:
- the rates document has shape version 2 (seasons, nightly room rates, occupancy, day of week, length of stay, supplements, rate plans),
- `RoomPricer` prices any stay to integer USD, night by night, matching the fixture's `reference_quotes`,
- taxes and fees are a configurable list (PNG/TCT retired as concepts),
- the panel's Rates page edits the new document and runs the price check on stays.

Existing bookings are untouched: their `price_lines` were frozen at sale (core rule 7).

**Read first (whole sprint):** 09 H8, H9, H17; `laravel.mdc` "Configuration documents" and "Money"; `Services/Pricing/*`, `Support/Config/Documents/Rate*.php`, `PngFees.php`, `FeesSettings.php`, `Services/Config/DepartureConfigChecks.php`, `docs/requirements/examples/hotel-seed-data.json` (`reference_quotes`).

## Tasks

| # | Task | Repo |
|---|---|---|
| 01 | [Rates document v2](01-rates-document-v2.md) | api |
| 02 | [`RoomPricer`](02-room-pricer.md) | api |
| 03 | [Rate plans](03-rate-plans.md) | api |
| 04 | [Taxes and fees](04-taxes-and-fees.md) | api |
| 05 | [Quoter, price check and config checks on stays](05-quoter.md) | api |
| 06 | [Panel: rates editor and price check](06-panel-rates.md) | panel |
| 07 | [Sprint close: e2e and report](07-sprint-close.md) | api |

**E2E scenarios:** HRATE-01 … HRATE-05 (new, batch B23). Retired: RATE-02, RATE-03, RATE-04, RATE-05, RATE-06. RATE-01 rewritten for the stay price check (still P2).

## Two documents, one rule
Yacht bookings still being created during the migration (through the adapter) need the v1 shape until Sprint 19 ends. Decision for this sprint: the published rates document carries **both** `years` (v1, frozen, not editable in the UI any more) and the v2 keys. `CabinPricer` reads `years`; `RoomPricer` reads v2. Sprint 22 removes `years`.
