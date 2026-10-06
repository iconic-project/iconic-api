# Requirements index

Every file under `docs/requirements/`, grouped as specs · contract · decisions · prototypes · examples · screenshots.

Also in this folder (not requirement docs):

- `README.md` — package readme for the prototypes and developer handoff (v1.3, 15 Sep 2026)
- `INDEX.md` — this index

---

## Specs

- `01-functional-spec.md` — module-by-module functional spec
- `02-data-model.md` — entities, fields, states, derived values
- `03-business-rules.md` — every rule with its source code (FIN-/OPS-/TEC-) and where it is configured
- `06-build-backlog.md` — suggested build order, what is NOT in the prototype, non-functional requirements
- `booking_engine_SPEC.md` — engine prototype v3, written before the 12 Sep 2026 decisions: design reference only, business rules superseded (see 08-dev-decisions.md B1–B7)
- `booking_engine_README.md` — engine prototype v3, written before the 12 Sep 2026 decisions: design reference only, business rules superseded (see 08-dev-decisions.md B1–B7)

## Contract

- `04-booking-engine-contract.md` — what the RMS must publish to iconic.co and the sync rules
- `07-three-system-integration-contract.md` — ENGINE ↔ RMS ↔ CRM: field ownership, event catalogue, identity, jobs, data map

## Decisions

- `09-hotel-generalisation.md` — hotel generalisation (stays, rooms, nights). Ranks above `08` for stays, rooms and nights.
- `05-decisions-and-open-questions.md` — Iconic's decisions of 12 Sep 2026 + what is still open
- `08-dev-decisions.md` — development-team architecture decisions and resolved contradictions. Highest authority except where `09` ranks above it (stays, rooms and nights).

## Prototypes

- `prototype/rms_index.html` — RMS prototype (single file)
- `prototype/crm_index.html` — CRM prototype (single file)
- `prototype/booking_engine_index.html` — booking-engine prototype (single file)

## Examples

- `examples/booking-engine-feed.json` — live export of the engine feed contract (kept until the engine frontend switches)
- `examples/engine-property.json` — `GET /api/engine/property`
- `examples/engine-availability.json` — `GET /api/engine/availability`
- `examples/seed-data.json` — every entity with realistic sample data (fixtures / test data)

## Screenshots

Visual acceptance reference. 18 RMS screenshots + 8 CRM screenshots (filenames starting with crm-).

- `screenshots/01-calendar.png` — RMS calendar
- `screenshots/02-bookings.png` — RMS bookings list
- `screenshots/03-payments.png` — RMS payments
- `screenshots/04-documents-manifests.png` — RMS documents and manifests
- `screenshots/05-guest-experience.png` — RMS guest experience
- `screenshots/06-agent-portal.png` — RMS agent portal
- `screenshots/07-rates.png` — RMS rates
- `screenshots/08-itineraries.png` — RMS itineraries
- `screenshots/09-departures.png` — RMS departures
- `screenshots/10-offers.png` — RMS offers
- `screenshots/11-engine-settings.png` — RMS engine settings
- `screenshots/12-business-rules.png` — RMS business rules
- `screenshots/13-engine-map.png` — RMS engine map
- `screenshots/14-booking-overview.png` — RMS booking overview
- `screenshots/15-booking-guests.png` — RMS booking guests
- `screenshots/16-invoice.png` — RMS invoice
- `screenshots/17-invoice-totals.png` — RMS invoice totals
- `screenshots/18-change-history.png` — RMS change history
- `screenshots/crm-01-pipeline.png` — CRM pipeline
- `screenshots/crm-02-sync.png` — CRM sync and field ownership
- `screenshots/crm-03-tasks.png` — CRM tasks
- `screenshots/crm-04-campaigns.png` — CRM campaigns
- `screenshots/crm-05-docs.png` — CRM docs
- `screenshots/crm-06-activity.png` — CRM activity
- `screenshots/crm-07-privacy.png` — CRM privacy
- `screenshots/crm-08-b2b.png` — CRM B2B
