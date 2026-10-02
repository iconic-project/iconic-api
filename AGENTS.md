# iconic-api — agent notes

All PHP, Composer, Artisan, Pest, Pint and Larastan commands run **inside Docker**. Never install PHP or Laravel Boost on the host.

```bash
docker compose exec app sh -c "composer install"
docker compose exec app sh -c "php artisan migrate"
```

Do not run `docker compose exec app sh` on its own (interactive shells hang).

Project rules: `.cursor/rules/*.mdc`. Architecture and resolved contradictions: `docs/requirements/08-dev-decisions.md`.

## Cursor Cloud specific instructions

Browser e2e for cloud agents lives in [`tests/e2e/README.md`](tests/e2e/README.md). Run `tests/e2e/bin/up.sh` and wait for `ALL UP` before any scenario. Do not change application code during a run.
