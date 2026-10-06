# HPOR-01 · Agency availability and rates
- **Tags:** sprint-20, portal
- **Priority:** P1
- **Batch:** B26
- **Users:** Ada Agent
- **Start:** reset

## Why
The agency searches a stay and reads net rates. A published public nightly does not appear.

## Steps
1. Sign in as Ada at `http://localhost:3002/login` (`ada@portal.test` / `password`).
2. Open `/rates`. Read the Family row in the Peak column.
3. Open `/availability`. Check-in **21 Dec 2026**, check-out **25 Dec 2026**, adults `2`. Click `Show`.

## Expected
- [ ] E1 · Rates heading includes the net line (`NET RATES`) and the agency commission percent. The Family Peak cell is a net amount. The public nightly `340` is not on the page.
- [ ] E2 · Family is listed with rooms left and a net price for the four nights. The public nightly `340` is not on the row. `Request` is present.

## Notes
Peak Family nightly in the fixture is 340. The cell must be `Agency::netOf` of that figure. Hotel seed does not call `DemoAgenciesSeeder`, so `ada@portal.test` is missing after reset. Stop and class ENV. Do not create the agency during the run.
