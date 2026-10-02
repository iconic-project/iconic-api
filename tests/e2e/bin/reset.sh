#!/usr/bin/env bash
# Return the system to the documented start state.
set -euo pipefail

# shellcheck source=./_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/_lib.sh"

require_destructive_ok "reset.sh"

say "reset.sh  COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}"

say "migrate:fresh --seed --force"
in_app "php artisan migrate:fresh --seed --force"

say "Asserting demo users exist"
count="$(compose exec -T app php artisan tinker --execute='echo App\Models\User::query()->whereIn("email", ["carolina@iconic.test","mateo@iconic.test","lucia@iconic.test","cfo@iconic.test"])->count();' | tr -d '[:space:]')"
if [ "${count}" != "4" ]; then
  die "Expected four demo users after seed, found '${count}'. Is APP_ENV=local? DemoUsersSeeder only runs in local and testing."
fi
say "Demo users present (4)"

say "Flushing Redis"
compose exec -T redis redis-cli FLUSHALL

say "Emptying Mailpit"
curl -sS -X DELETE http://localhost:8025/api/v1/messages >/dev/null \
  || die "Could not empty Mailpit at http://localhost:8025"

say "iconic:config-verify"
in_app "php artisan iconic:config-verify"

fix_app_writable_dirs

say "reset.sh done"
