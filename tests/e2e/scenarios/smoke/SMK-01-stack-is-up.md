# SMK-01 · Stack is up
- **Tags:** smoke
- **Priority:** P1
- **Users:** none (public pages)
- **Start:** reset not required (run after `up.sh`)

## Why
If health, panel login, engine chrome or Mailpit is down, every later scenario is an ENV failure.

## Steps
1. `curl -sS http://localhost:8000/api/health` (or open it in the browser).
2. Open `http://localhost:3001/login`.
3. Open `http://localhost:3000/`.
4. Open `http://localhost:8025`.

## Expected
- [ ] E1 · Health JSON has `"status":"ok"` and `checks.db`, `checks.redis`, `checks.queue` all `"ok"`.
- [ ] E2 · Panel `/login` shows the HILO wordmark, subtitle `RMS · REVENUE ENGINE`, title `Sign in`, fields `Email` and `Password`, button `Sign in`, link `Forgot password?`.
- [ ] E3 · Engine home shows header brand `HILO`, subtitle `INTIMATE YACHT EXPEDITIONS`, nav `Expeditions` and `Private Charter`, hero coords `0°40′S 90°33′W · Galápagos, Ecuador`, footer line `Iconic · Intimate yacht expeditions · Galápagos`.
- [ ] E4 · Mailpit UI loads (not an error page). The `/livez` endpoint is 200.

## Notes
`queue: ok` is the Redis queue connection. Horizon is checked by `up.sh` separately (`php artisan horizon:status`).
