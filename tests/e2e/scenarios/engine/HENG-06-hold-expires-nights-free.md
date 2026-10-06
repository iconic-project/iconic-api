# HENG-06 · Hold expires and the nights are free
- **Tags:** sprint-20, engine
- **Priority:** P2
- **Batch:** B25
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
An expired hold frees the nights. The request stays REQUESTED. Nothing cancels it.

## Steps
1. **Guest.** Repeat the HENG-03 pay-later path for **Family**, 21–25 Dec 2026, email `e2e.heng06@iconic.test`. Note the reference.
2. **Carolina.** Open `http://localhost:3001/rms/reservations/calendar`. Click `Next` until 21 Dec 2026 is on screen. Read **Family free** on 21–24 Dec 2026.
3. From the iconic-api root, with the e2e compose project:

   `COMPOSE_PROJECT_NAME=iconic-e2e docker compose exec app sh -c "php artisan inventory:expire-hold <reference>"`

4. Reload the timeline and the booking request.

## Expected
- [ ] E1 · Before the command, **Family free** is `5` on nights outside the stay and `4` on 21, 22, 23, and 24 Dec 2026. The fixture has five Family rooms (107, 207, 305, 306, 307) and none are claimed in December. One hold leaves four.
- [ ] E2 · After the command, **Family free** is `5` again on those nights. The request status is still REQUESTED.
- [ ] E3 · The booking is not cancelled.

## Notes
`inventory:expire-hold` is a documented local/testing command. It is not a `db-check` write. It sets the hold's `expires_at` in the past and runs `ClaimService::releaseExpired()`. Family room count is the fixture: 107, 207, 305, 306, 307.
