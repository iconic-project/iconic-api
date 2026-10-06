# EXT-04 · Catalogue price change; existing extra keeps its rate
- **Tags:** sprint-6, extras
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
The extras catalogue is a versioned document. A later publish must not rewrite frozen `booking_extras` (G4 / I8). 0011 already has FLT × 2 at 420.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/rates`. Scroll to the extras catalogue editor (not the rates years).
2. Change FLT **Price (USD)** from `420` to `500`. Code stays read-only. Approval ref `E2E-EXT-04`. Confirm note includes `Existing booking extras keep the rate they were sold at.` Publish.
3. Open ANK-2026-0011 (J. & P. Okafor). **Extras** tab. Read the seeded flights row.
4. Open ANK-2026-0014. **Extras** → Add a service → pick the flights item. Read the prefilled Rate (USD).

## Expected
- [ ] E1 · Publish succeeds. History / version bar shows a new extras version (V2) with approval `E2E-EXT-04` and Carolina.
- [ ] E2 · 0011 flights stay rate `USD 420`, amount `USD 840` (qty 2). Not 500 / 1,000.
- [ ] E3 · New add form on 0014 prefills Rate `500`.

## Notes
Codes are immutable once published. Names, units, prices and flags may change. Retire with `active: false`.
