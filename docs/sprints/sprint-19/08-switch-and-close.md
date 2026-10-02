# 19-08 — Switch seed mode, delete the adapter, sprint close

**Repo:** iconic-api
**Depends on:** 19-01 … 19-07

## Build
1. `HotelSeeder` seeds bookings, claims, payments and groups from `hotel-seed-data.json` (the `TODO(Sprint 19)` from 16-06), through the Actions (never raw inserts), with `system: true` history.
2. Default `ICONIC_SEED_MODE=hotel` in `.env.example`, `.env.testing.example`, e2e environment. Yacht mode stays available until Sprint 22.
3. Delete `LegacyDepartureClaims` and any remaining caller (`grep -rn LegacyDepartureClaims`). Fix what breaks.
4. New scenarios (`tests/e2e/scenarios/hotel/`):
   - **HBKG-01** Create a 2-night midweek stay (P1)
   - **HBKG-02** Three-room group with different dates (P1)
   - **HBKG-03** Restriction refusal and override with reason (P1)
   - **HBKG-04** Check in at any hour on the arrival day; refused the day before (P1)
   - **HBKG-05** Early departure credits unused nights (P1)
   - **HBKG-06** Extend an in-house guest (P1)
   - **HBKG-07** No-show releases following nights (P1)
   - **HBKG-08** Move room, timeline updates (P2)
   - **HBKG-09** Balance due counts from arrival (P2)
   - **HBKG-10** Night audit raises alerts and changes no status (P2)
5. Rewrite or retire BKG-*; run P1; update `LEDGER.md`; close `REPORT.md`.

## Done when
`ICONIC_SEED_MODE=hotel` fresh seed + all P1 green; no code path creates a booking with a departure.
