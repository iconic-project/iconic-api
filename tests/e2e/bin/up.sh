#!/usr/bin/env bash
# Bring the whole product up and block until healthy.
set -euo pipefail

# shellcheck source=./_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/_lib.sh"

say "up.sh  ICONIC_ROOT=${ICONIC_ROOT}  COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}"

check_memory
ensure_docker
ensure_networks
ensure_log_dir

install_env "${E2E_ENV_DIR}/api.env" "${API_ROOT}/.env"
install_env "${E2E_ENV_DIR}/api.testing.env" "${API_ROOT}/.env.testing"

say "Starting mysql, redis, mailpit (wait until healthy)"
compose up -d --wait mysql redis mailpit

say "Starting app (no --wait — health is 500 until composer + APP_KEY)"
compose up -d app

say "composer install --no-interaction"
in_app "composer install --no-interaction"

if app_key_empty; then
  say "APP_KEY is empty — php artisan key:generate"
  in_app "php artisan key:generate --force"
else
  say "APP_KEY already set — skipping key:generate"
fi

fix_app_writable_dirs

say "php artisan storage:link --force"
in_app "php artisan storage:link --force"

wait_api_health "${E2E_WAIT_SECS}"
wait_horizon 60

"${E2E_BIN_DIR}/reset.sh"

install_env "${E2E_ENV_DIR}/frontends.env" "${PANEL_DIR}/.env"
install_env "${E2E_ENV_DIR}/frontends.env" "${ENGINE_DIR}/.env"
install_env "${E2E_ENV_DIR}/frontends.env" "${PORTAL_DIR}/.env"

print_heads

ensure_pnpm
pnpm_frozen "${UI_DIR}"
pnpm_frozen "${PANEL_DIR}"
pnpm_frozen "${ENGINE_DIR}"
pnpm_frozen "${PORTAL_DIR}"

start_frontend() {
  local name="$1"
  local dir="$2"
  local port="$3"
  local pidfile="${E2E_LOG_DIR}/${name}.pid"
  local logfile="${E2E_LOG_DIR}/${name}.log"

  if [ -f "${pidfile}" ] && kill -0 "$(cat "${pidfile}")" 2>/dev/null; then
    say "${name} already running (pid $(cat "${pidfile}"))"
    return 0
  fi

  if port_in_use "${port}"; then
    die "Port ${port} is already in use. Stop the other ${name} (working-tree pnpm dev?) before up.sh. The e2e preview must own ${port}."
  fi

  if [ "${E2E_MODE:-preview}" = "dev" ]; then
    say "Starting ${name} with pnpm dev on ${port}"
    (
      cd "${dir}"
      setsid pnpm exec nuxt dev --port "${port}" --host 0.0.0.0 >"${logfile}" 2>&1 < /dev/null &
      echo $! >"${pidfile}"
    )
  else
    say "Building ${name}"
    (cd "${dir}" && pnpm build)
    if [ ! -f "${dir}/.output/server/index.mjs" ]; then
      die "${name} build did not produce .output/server/index.mjs"
    fi
    say "Starting ${name} preview on ${port} (node .output/server/index.mjs)"
    (
      cd "${dir}"
      setsid env PORT="${port}" HOST=0.0.0.0 node .output/server/index.mjs >"${logfile}" 2>&1 < /dev/null &
      echo $! >"${pidfile}"
    )
  fi

  sleep 1
  if [ -f "${logfile}" ] && grep -q 'EADDRINUSE' "${logfile}"; then
    die "${name} failed to bind :${port} (EADDRINUSE). See ${logfile}"
  fi
  if [ ! -f "${pidfile}" ] || ! kill -0 "$(cat "${pidfile}")" 2>/dev/null; then
    die "${name} exited immediately. See ${logfile}"
  fi
}

start_frontend panel "${PANEL_DIR}" 3001
start_frontend engine "${ENGINE_DIR}" 3000
start_frontend portal "${PORTAL_DIR}" 3002

wait_http "http://localhost:3001/login" "${E2E_WAIT_SECS}"
wait_http "http://localhost:3000/" "${E2E_WAIT_SECS}"
wait_http "http://localhost:3002/login" "${E2E_WAIT_SECS}"
wait_http "http://localhost:8025/livez" 30

"${E2E_BIN_DIR}/status.sh"

cat <<EOF

URLs
  Panel     http://localhost:3001
  Engine    http://localhost:3000
  Portal    http://localhost:3002
  API       http://localhost:8000
  API docs  http://localhost:8000/docs/api
  Mailpit   http://localhost:8025

EOF
