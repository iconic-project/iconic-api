# E2E run · 2026-09-19 20:35 · example-smk-rate

- **Agent:** local (harness verification)
- **Prompt:** Run SMK-01, SMK-02 and RATE-03 from the scenario files
- **Git:** working tree (e2e harness, uncommitted)
- **Mode:** preview
- **Stack:** `status.sh` ALL UP

## Summary

| ID | Result | Class | Notes |
|---|---|---|---|
| SMK-01 | PASS | | Health JSON ok; login brand + form; engine chrome + `API · OK`; Mailpit UI |
| SMK-02 | PASS | | All four land on `/rms/reservations/calendar`; headers match; sign-out → `/login` |
| RATE-03 | PASS | | 2027 Published column matches all eight reference totals; Difference `no change` |

**Counts:** 3 passed · 0 failed · 0 not run

## Environment

- Memory available at `up.sh`: 9.34 GiB (warn threshold `E2E_MIN_MEM_GB=6`, fail below 3)
- `COMPOSE_PROJECT_NAME`: `iconic-e2e`
- First `up.sh`: no `vendor/`, `.env` copied from `api.env` with empty `APP_KEY`, then `key:generate`. Staged compose (mysql/redis/mailpit `--wait`, then app, then composer).
- Isolated copy at `/tmp/iconic-e2e-verify`. Working MySQL volume `iconic-api_mysql-data` (created 2026-09-18T13:26:28+03:30) untouched. E2e volume `iconic-e2e_mysql-data`.

## Scenarios

### SMK-01 · Stack is up

- **Result:** PASS
- E1 · `{"status":"ok","checks":{"db":"ok","redis":"ok","queue":"ok"}}`
- E2 · `/login`: ICONIC wordmark, `RMS · REVENUE ENGINE`, heading `Sign in`, fields `Email` / `Password`, button `Sign in`, link `Forgot password?`
- E3 · Engine home: `INTIMATE YACHT EXPEDITIONS`, nav Expeditions / Private Charter, coords, footer `Iconic · Intimate yacht expeditions · Galápagos`, `API · OK`
- E4 · Mailpit UI title `Mailpit - localhost`

### SMK-02 · Every demo user can sign in and out

- **Result:** PASS
- Carolina → `/rms/reservations/calendar`, `CAROLINA M. — ADMIN`, Permissions + Business Rules, RMS/CRM switch
- Sign out → `/login`
- Mateo → calendar, `MATEO R. — MANAGER`, no Permissions / Business Rules, switch visible
- Lucía → calendar, `LUCÍA B. — SALES EXEC`, same gating
- CFO → calendar, `CFO (EXTERNAL) — EXTERNAL FINANCE`, no switch, no New Reservation
- Sign out → `/login`

### RATE-03 · Price check matches the reference prices

- **Result:** PASS
- Sailing year 2027. Columns Scenario · Published · Draft · Difference.
- Suite · 2 adults `USD 26,600`
- Suite · 1 adult (single) `USD 23,275`
- Suite · 3 adults (triple) `USD 35,910`
- Suite · 2 adults + 1 child `USD 37,905`
- Owner's Suite · 2 adults `USD 50,000`
- Suite · 2 adults · festive `USD 28,100`
- Charter · 1 week `USD 199,500`
- Charter · festive week `USD 211,500`
- Every Difference `no change`

## Notes for humans

Login labels render in uppercase via CSS; i18n strings are sentence case (`Sign in`, `Email`). Screenshot fonts in the agent browser were garbled; a11y tree and `innerText` were used.
