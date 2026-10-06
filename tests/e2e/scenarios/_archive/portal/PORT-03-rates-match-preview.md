# PORT-03 · Portal rates match the RMS preview, with no public price
- **Tags:** sprint-13, sprint-20, portal
- **Priority:** P1
- **Batch:** B16
- **Users:** Ada Agent + Carolina
- **Start:** reset

## Why
The agent sees the same net stay rates as the RMS preview for that agency. A published public nightly must not appear anywhere on the portal.

## Steps
1. Sign in as Ada at `http://localhost:3002/login`. Read `/rates`.
2. In another context, sign in as Carolina. Open Blue Latitude Travel on `http://localhost:3001/rms/commercial/b2b` and read the portal preview stay rates (seasons × room types, plans, length of stay, supplements).
3. As Ada, open **Availability**, **Bookings** and **Commissions**. On each page, search the visible text for a published public nightly from the rates document.

## Expected
- [ ] E1 · Rates heading includes `NET RATES (PUBLIC − 10%) · PUBLIC PRICES NEVER SHOWN`.
- [ ] E2 · The portal matrix and the preview stay rates show the same seasons, room types, net nightlies, plans, length-of-stay bands and supplement nets.
- [ ] E3 · No public nightly from the published document appears on Rates, Availability, Bookings or Commissions.

## Notes
The yacht year table (suite / owner's suite / charter) stays on the RMS preview as `net_rates`. The portal rates page and the preview `stay_rates` are the season matrix. Nets are `Agency::netOf`. If the preview stay rates and the portal agree with each other, that is the check. If either screen shows a public nightly, that is a `BUG`.
