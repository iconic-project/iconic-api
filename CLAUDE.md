# iconic-api — agent notes

Laravel 13 API for Iconic (hotel reservations).

All PHP, Composer, Artisan, Pest, Pint and Larastan commands run **inside Docker**. Never install PHP or Laravel Boost on the host.

```bash
docker compose exec app sh -c "composer install"
docker compose exec app sh -c "php artisan migrate"
```

Do not run `docker compose exec app sh` on its own (interactive shells hang).

Project rules: `.cursor/rules/*.mdc`. Architecture and resolved contradictions: `docs/requirements/08-dev-decisions.md`.

## Requirements and sprints

- Requirements: [`docs/requirements/`](docs/requirements/) — start with [`INDEX.md`](docs/requirements/INDEX.md). For stays, rooms and nights, [`09-hotel-generalisation.md`](docs/requirements/09-hotel-generalisation.md) ranks above [`08-dev-decisions.md`](docs/requirements/08-dev-decisions.md).
- Sprints: [`docs/sprints/`](docs/sprints/). Hotel migration (sprints 16–22): [`HOTEL-ROADMAP.md`](docs/sprints/HOTEL-ROADMAP.md).
