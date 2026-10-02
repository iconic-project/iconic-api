# 17-07 — Panel: rooms × nights calendar, blocks, restrictions

**Repo:** iconic-panel
**Depends on:** 17-04, 17-05, 17-06; 16-07 (`AnkStayInput`)
**Read first:** `app/pages/rms/reservations/calendar.vue`, `yacht-layout.vue`, `rms/operations/blocks.vue`, `rms/booking-engine/departures.vue`, HILO brand book

## Why
The yacht calendar lists departures and a cabin layout per departure. A hotel calendar is a timeline: rooms down the side, nights across, stays as bars.

## Build
1. `rms/reservations/calendar.vue` → **timeline view**: rooms grouped by room type (collapsible), 14/31-night window with prev/next and "today", horizontally scrollable. Bars per `claim_group`, coloured by state (sold, held, blocked) with reference and surname. Header rows: free rooms per type per night, occupancy % per night. Click a bar → booking drawer (read-only in this sprint) or block drawer. Click-drag across free cells on one room → "Block these nights" (if permitted).
2. `yacht-layout.vue` → retired from navigation (route kept, redirects to calendar). Remove from the menu config.
3. `rms/operations/blocks.vue`: create a block with `AnkStayInput` + rooms or type+count; list shows ranges; shorten and release with mandatory note.
4. New `rms/inventory/restrictions.vue` (menu: Inventory → Restrictions): month grid per room type showing stop-sell / CTA / CTD / min-max; bulk editor (date range, weekdays, types, fields). Read-only without `inventory.manage_restrictions`.
5. `rms/booking-engine/departures.vue` stays (yacht) but shows a banner "Departures are retired in Sprint 22 — use Restrictions".
6. i18n for all strings; no business values in the frontend (thresholds come from the API).

## Tests
Vitest for the bar layout maths (a stay that starts before the window, ends after it, 1-night stays, adjacent stays on one room) and the restriction editor payload.

## Done when
Lint, typecheck and tests green; the calendar renders the hotel fixture's rooms with no horizontal overflow of the page body.
