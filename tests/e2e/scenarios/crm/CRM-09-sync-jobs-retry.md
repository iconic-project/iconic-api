# CRM-09 · Sync jobs show last runs; a failed job appears and retries
- **Tags:** sprint-9, crm
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Sync & Field Ownership is the live schedule, not a static list. A failed side effect must show and Retry must clear it.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/system/sync`.
2. Read the three KPIs, **Scheduled jobs** and **Failures**.
3. Confirm every command in `fixtures/reference-values.md` (Scheduled jobs) is a row: cadence, last run `—`, outcome `—`, a next-run stamp.
4. Insert one `failed_jobs` row (same shape as `SyncFailuresTest`; `db-check.sh` is read-only so this is tinker):

```bash
docker compose exec app sh -c "php artisan tinker --execute=\"Illuminate\\\\Support\\\\Facades\\\\DB::table('failed_jobs')->insert(['uuid' => (string) Illuminate\\\\Support\\\\Str::uuid(), 'connection' => 'sync', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\\\Listeners\\\\SendOnPaymentSettled', 'job' => 'Illuminate\\\\Queue\\\\CallQueuedHandler@call']), 'exception' => \\\"RuntimeException: Stripe timeout\\n#0 /app/Listener.php\\\", 'failed_at' => now()]);\""
```

5. Reload `/crm/system/sync`. Read **Failures** and the failures-open KPI.
6. Click **Retry** on `App\Listeners\SendOnPaymentSettled`. Wait for the button to finish.

## Expected
- [ ] E1 · Fresh seed KPIs: **Jobs failing** `0`, **Failures open** `0`, **Merges this month** `0`. Failures empty: `No failed jobs or deliveries.` ⚠ UNVERIFIED — `SyncJobsTest` (null outcomes) + task 07 browser after a manufactured failure.
- [ ] E2 · Jobs include `inventory:release-expired-holds` and `engine:expire-stripe-checkouts` (`* * * * *`); `iconic:crm-tasks` and `iconic:alerts` (`*/5 * * * *`); `iconic:journeys` (`*/15 * * * *` · `Pacific/Galapagos`); `iconic:flag-overdue`, `iconic:retention`, `iconic:events-retention`, `iconic:documents-due` (`0 0 * * *` · `Pacific/Galapagos`); `iconic:voyage-status` (`15 0 * * *` · `Pacific/Galapagos`); `iconic:ledger-check` (`0 2 * * *` · `Pacific/Galapagos`); `iconic:commission-scan` (`30 2 * * *` · `Pacific/Galapagos`); `iconic:manifests-due` (`0 6 * * *` · `Pacific/Galapagos`); `iconic:occupancy-check` (`0 7 * * *` · `Pacific/Galapagos`); `iconic:document-check` (`0 * * * *` · `Pacific/Galapagos`). Last run / outcome `—`. Next run is a timestamp, not `—`. `telescope:prune --hours=48` only if Telescope is installed.
- [ ] E3 · After the insert: Failures shows kind `job`, name `App\Listeners\SendOnPaymentSettled`, detail `RuntimeException: Stripe timeout`. Failures open `1`. **Retry** is shown (Admin has `sync.retry`).
- [ ] E4 · Retry: the clicked button disables while pending. Toast `Retry queued`. The row leaves. Failures open returns to `0`.

## Cross-checks
- Before Retry: `bin/db-check.sh 'Illuminate\Support\Facades\DB::table("failed_jobs")->count()'` → `1`.
- After Retry: the same count is `0`.

## Notes
The insert is a scenario setup step, not an application change. Task 07 manufactured the same row. Do not change application code if Retry is missing — that is a **BUG**.
