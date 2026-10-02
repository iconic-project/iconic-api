# 16-07 — Shared UI: stay types and date-range input

**Repo:** iconic-ui
**Depends on:** 16-04 (API types), 16-02/03 (regenerated OpenAPI)
**Read first:** 09 H1–H3; `iconic-ui/app/components/AnkDateInput.vue`, `composables/useDates.ts`, `scripts/types-api.sh`, HILO brand book

## Why
Panel, engine and portal will all pick stays. One shared component keeps the nights maths and the look identical everywhere.

## Build
1. Run `pnpm types:api` against the API with tasks 02–05 merged; commit the regenerated types.
2. `useDates.ts`: add `nightsBetween(checkIn, checkOut)`, `eachNight(checkIn, checkOut)`, `addNights(date, n)`, `formatStay(checkIn, checkOut)` → "Tue 3 Mar – Fri 6 Mar 2028 · 3 nights". Pure functions, unit-tested, no time zones (dates are plain `YYYY-MM-DD`).
3. `AnkStayInput.vue`: two-month range picker built on Nuxt UI's calendar (`@internationalized/date` already present). Props: `modelValue: {check_in, check_out} | null`, `minNights`, `maxNights`, `minDate`, `maxDate`, `nightInfo?: (date) => {disabled?, closedToArrival?, closedToDeparture?, price?, minStay?}`. Behaviour: first click = arrival (refuses closed-to-arrival days), second click = departure (refuses closed-to-departure days and lengths outside min/max, explains why inline). Shows the nights count. Keyboard accessible.
4. `AnkNights.vue`: small pill "3 nights".
5. i18n strings for every label (core rule 8).
6. Add both to the `.playground`.

## Tests
Vitest: `useDates` helpers (month/year/leap crossing); `AnkStayInput` selection flow, closed-to-arrival refusal, min-stay refusal, clearing.

## Out of scope
Using the component in the apps (later sprints).

## Done when
`pnpm lint && pnpm typecheck && pnpm test` green; the playground shows the picker in light and dark themes.
