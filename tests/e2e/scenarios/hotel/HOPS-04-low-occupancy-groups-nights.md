# HOPS-04 · Low-occupancy alert groups consecutive nights
- **Tags:** sprint-21, hotel, alerts
- **Priority:** P2
- **Batch:** B27
- **Users:** Carolina
- **Start:** reset

## Why
Nights under the threshold that follow each other are one alert. One night is not its own alert when it sits inside that run.

## Steps
1. In the stack's API container, run `php artisan iconic:occupancy-check` once. Same container as `tests/e2e/bin/db-check.sh`.
2. Sign in as Carolina. Open `http://localhost:3001/rms/operations/alerts`. Filter kind **Low occupancy**.

## Expected
- [ ] E1 · At least one title matches `Low occupancy HTL {start} to {end}` with two different dates.
- [ ] E2 · That alert's sentence names one night count for the whole span (`for {n} nights`) and `{n}` is greater than 1.
- [ ] E3 · No second Low occupancy alert exists whose date falls strictly inside that same span.

## Notes
The job is daily at 07:00 Galápagos. This step runs it once. The seeded threshold is 40%. A single-night title with no ` to ` may also exist outside the span. That is not a failure.
