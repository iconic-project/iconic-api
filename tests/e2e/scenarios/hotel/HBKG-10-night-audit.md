# HBKG-10 · Night audit raises alerts and changes no status
- **Tags:** sprint-19, hotel, bookings
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Night audit raises operational alerts. It does not change a stay status.

## Steps
1. Hotel seed. Sign in as Carolina. Note the status of HTL-019 (in house) and HTL-001 (confirmed).
2. Run `docker compose exec app sh -c "php artisan iconic:night-audit"` from `iconic-api`.
3. Open `http://localhost:3001/rms/reservations/front-desk`. Read the night-audit strip, or `GET /api/alerts?section=rms&state=open`.
4. Read HTL-019 and HTL-001 again.

## Expected
- [ ] E1 · The command finishes without cancelling or moving a booking.
- [ ] E2 · HTL-019 is still **In house**. HTL-001 is still **Confirmed**. No booking status changed in this run.
- [ ] E3 · At least one open RMS alert is an arrival not checked in, an in-house guest past check-out, or a departure not checked out. HTL-019 (checked in 8 Jul 2026, check-out 10 Jul) is past check-out on a later business date, so an in-house-past-check-out alert is expected when the clock is after 10 Jul 2026.

## Notes
The audit never writes `bookings.status`.
