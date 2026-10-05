# iconic-api

Laravel 13 API for Iconic (hotel reservations). RMS and CRM are two sections of this one app. The public booking engine is a separate frontend.

PHP, Composer, Artisan, Pest, Pint and Larastan run **inside Docker**. Do not run them on the host.

```bash
docker compose exec app sh -c "…"
```

## Prerequisites

- Docker Compose
- Two **external** Docker networks (shared infra). Create them once if they do not exist:

```bash
docker network create traefik-network
docker network create mysql-network
```

## First-time setup

From this directory:

1. Copy the env files and set the MySQL passwords. Use the **same** password for `MYSQL_PASSWORD` and `DB_PASSWORD`. Leave no password blank.

```bash
cp .env.example .env
cp .env.testing.example .env.testing
```

2. Start the stack. The app waits until MySQL and Redis are healthy:

```bash
docker compose up -d --wait
```

3. Install PHP dependencies, generate keys, migrate:

```bash
docker compose exec app sh -c "composer install"
docker compose exec app sh -c "php artisan key:generate"
docker compose exec app sh -c "php artisan key:generate --env=testing"
docker compose exec app sh -c "php artisan migrate"
docker compose exec app sh -c "php artisan storage:link"
```

4. Generate a **second** key for passport numbers and medical notes. Paste it into `SENSITIVE_DATA_KEY` in `.env` and `.env.testing`. Do **not** copy `APP_KEY`.

```bash
docker compose exec app sh -c "php artisan key:generate --show"
```

5. Confirm the API is up:

```bash
curl http://localhost:8000/api/health
```

A 200 response with `"status":"ok"` and `db` / `redis` / `queue` all `ok` means setup is done.

If `.env` already exists (you have run this before), skip step 1. After `docker compose down -v` the MySQL volume is empty — run steps 2–5 again (Composer and keys can be skipped if `vendor/`, `APP_KEY` and `SENSITIVE_DATA_KEY` are already there).

## Start and stop

```bash
docker compose up -d
docker compose ps
docker compose logs app

docker compose stop
docker compose down
```

`docker compose down -v` also deletes the MySQL volume. The next start recreates `iconic` and `iconic_test` via `docker/mysql/init/01-databases.sh`; you must migrate again.

## Artisan and Composer

```bash
docker compose exec app sh -c "php artisan migrate"
docker compose exec app sh -c "php artisan tinker"
docker compose exec app sh -c "composer install"
docker compose exec app sh -c "composer require package/name"
```

Do not run `docker compose exec app sh` on its own (an interactive shell). Composer may print a `safe.directory` warning for `/app`; it is harmless and the command still runs.

`iconic:retention` anonymises passport data and purges medical, dietary and accessibility notes on the published schedule (B4). **Do not run it in production until the client confirms those periods** (sprint 6 README, client question 1). `--dry-run` prints the counts and writes nothing.

## Tests, lint, static analysis

```bash
docker compose exec app sh -c "composer check"
```

That runs Pest, Pint (`--test`) and Larastan (level 6). Individually:

```bash
docker compose exec app sh -c "composer test"
docker compose exec app sh -c "composer lint"
docker compose exec app sh -c "composer analyse"
```

Tests use the `iconic_test` database (pinned in `phpunit.xml`). Credentials come from `.env.testing`.

## URLs

| What | URL |
|---|---|
| API | http://localhost:8000 |
| Health | http://localhost:8000/api/health |
| OpenAPI (Scramble, local only) | http://localhost:8000/docs/api |
| Horizon (local only) | http://localhost:8000/horizon |
| Telescope (local only) | http://localhost:8000/telescope |
| Mailpit | http://localhost:8025 |

Horizon runs inside the `app` container (supervisor). Telescope records requests, queries, jobs and exceptions locally only (`TELESCOPE_ENABLED`); it is off in tests and pruned after 48 hours. Route sections: `/api/rms/*`, `/api/crm/*`, `/api/engine/*`, `/api/auth/*`, `/api/stripe/webhook` (signed, no session).

## Stripe

The RMS creates Stripe Payment Links; Stripe's webhook (`POST /api/stripe/webhook`) settles them. Iconic stores Stripe ids only — never card numbers or last four digits (TEC-001).

Set these in `.env` (empty in `.env.example`; the client still owes test and live keys):

| Variable | What |
|---|---|
| `STRIPE_SECRET` | Secret or restricted key (`sk_test_…` / `rk_test_…`) |
| `STRIPE_PUBLISHABLE` | Publishable key (`pk_test_…`) |
| `STRIPE_WEBHOOK_SECRET` | Signing secret from `stripe listen` or the Dashboard |
| `STRIPE_MODE` | `test` locally; `live` in production |

Manual test-mode check: `stripe listen --forward-to http://localhost:8000/api/stripe/webhook`, create a deposit link in the RMS, pay it with card `4242 4242 4242 4242`.

## Mail

Transactional mail goes through Laravel’s SMTP mailer. Locally and in e2e it lands in Mailpit (`http://localhost:8025`, SMTP `mailpit:1025`). Production uses ordinary SMTP credentials (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`). The sending mailbox is still a client question; `From` is `MAIL_FROM_ADDRESS`, and `Reply-To` is the issuer email from business rules (`legal_entity.email`). Do not set `MAIL_MAILER` to `failover` — a failed send is recorded as failed and is not retried onto another transport.

Sibling apps (separate repos): booking engine `http://localhost:3000`, staff panel `http://localhost:3001`.

## Staff sign-in

Sanctum SPA cookie sessions. There is no “remember me”. The session lasts **480 minutes** of idle time (one working day). Role and permission changes apply on the user’s **next request** — permissions are read from the role on every request, not stored in the cookie.

Local demo users (seeded only in `local` and `testing`, password `password`):

| Name | Email | Role |
|---|---|---|
| Carolina M. | carolina@iconic.test | Admin |
| Mateo R. | mateo@iconic.test | Manager |
| Lucía B. | lucia@iconic.test | Sales Exec |
| CFO (external) | cfo@iconic.test | External finance (RMS only) |

The first admin in a non-demo environment: `php artisan iconic:create-admin you@example.com "You"`. The invitation email appears in Mailpit (`http://localhost:8025`); the command also prints the link.

## Seed mode

`ICONIC_SEED_MODE` chooses the inventory a fresh seed writes. The seeder reads it through `config('iconic.seed_mode')`. The default is `hotel`. `yacht` stays available until Sprint 22.

| Value | What `php artisan migrate:fresh --seed` writes |
|---|---|
| `hotel` | Hotel Demo (`HTL`) from `docs/requirements/examples/hotel-seed-data.json`: 1 property, 4 room types, 24 rooms, the fixture restrictions, and the fixture bookings whose nights have a season. Yacht inventory and yacht demo bookings are skipped. |
| `yacht` | ANAMARA and ANATIVA, plus the local demo departures, bookings and agencies |

Hotel inventory is seeded only when `APP_ENV` is `local` or `testing`, same as the demo users. Roles, published config, segments, journeys and message templates still seed in both modes.

```bash
docker compose exec -e ICONIC_SEED_MODE=hotel app sh -c "php artisan migrate:fresh --seed"
```

## Requirements and sprints

- Requirements: [`docs/requirements/`](docs/requirements/) — start with [`INDEX.md`](docs/requirements/INDEX.md) and [`08-dev-decisions.md`](docs/requirements/08-dev-decisions.md) (highest authority when documents disagree).
- Sprints: [`docs/sprints/`](docs/sprints/) — roadmap in [`ROADMAP.md`](docs/sprints/ROADMAP.md); hotel migration (sprints 16–22) in [`HOTEL-ROADMAP.md`](docs/sprints/HOTEL-ROADMAP.md). The current sprint is a folder `sprint-NN/` with a `README.md` and ordered task files.
