# Shared helpers for tests/e2e/bin/*.sh
# Sourced only — not executable.

set -euo pipefail

E2E_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$(cd "${E2E_BIN_DIR}/.." && pwd)"
API_ROOT="$(cd "${E2E_DIR}/../.." && pwd)"
ICONIC_ROOT="${ICONIC_ROOT:-$(cd "${API_ROOT}/.." && pwd)}"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-iconic-e2e}"

E2E_LOG_DIR="${E2E_DIR}/runs/.logs"
E2E_ENV_DIR="${E2E_DIR}/environment"
E2E_MIN_MEM_GB="${E2E_MIN_MEM_GB:-6}"
E2E_FAIL_MEM_GB=3
E2E_WAIT_SECS="${E2E_WAIT_SECS:-180}"

PANEL_DIR="${ICONIC_ROOT}/iconic-panel"
ENGINE_DIR="${ICONIC_ROOT}/iconic-engine"
UI_DIR="${ICONIC_ROOT}/iconic-ui"
PORTAL_DIR="${ICONIC_ROOT}/iconic-portal"

PNPM_VERSION="12.4.1"

say() {
  printf '==> %s\n' "$*"
}

warn() {
  printf 'WARNING: %s\n' "$*" >&2
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

compose() {
  docker compose -f "${API_ROOT}/docker-compose.yml" --project-directory "${API_ROOT}" "$@"
}

in_app() {
  compose exec -T app sh -c "$1"
}

# composer / artisan run as root via in_app; php-fpm and Horizon run as application.
# Without this, bind-mounted storage/ and bootstrap/cache stay root:root, GET / is 500,
# and reset-password mail cannot compile Blade views.
fix_app_writable_dirs() {
  say "chown storage and bootstrap/cache to application"
  in_app "chown -R application:application /app/storage /app/bootstrap/cache && chmod -R ug+rwX /app/storage /app/bootstrap/cache"
}

ensure_log_dir() {
  mkdir -p "${E2E_LOG_DIR}"
}

# Refuse migrate:fresh / down --wipe unless this is the isolated e2e project.
require_destructive_ok() {
  local action="$1"
  if [ "${COMPOSE_PROJECT_NAME}" = "iconic-e2e" ] || [ "${E2E_ALLOW_RESET:-}" = "1" ]; then
    return 0
  fi
  die "${action} would erase the '${COMPOSE_PROJECT_NAME}' project's database. Refusing unless COMPOSE_PROJECT_NAME=iconic-e2e or E2E_ALLOW_RESET=1."
}

mem_available_gb() {
  local kb
  kb="$(awk '/MemAvailable:/ { print $2 }' /proc/meminfo 2>/dev/null || echo 0)"
  awk -v kb="${kb}" 'BEGIN { printf "%.2f", kb / 1024 / 1024 }'
}

check_memory() {
  local avail warn_at
  avail="$(mem_available_gb)"
  warn_at="${E2E_MIN_MEM_GB}"

  say "Memory available: ${avail} GiB (warn below ${warn_at} GiB, fail below ${E2E_FAIL_MEM_GB} GiB)"
  say "This stack needs ~6 GiB for MySQL, Redis, Mailpit, PHP/Horizon and three Nuxt preview apps (panel, engine, portal)."

  if awk -v a="${avail}" -v f="${E2E_FAIL_MEM_GB}" 'BEGIN { exit (a < f) ? 0 : 1 }'; then
    die "Only ${avail} GiB available. Need at least ${E2E_FAIL_MEM_GB} GiB free to start the e2e stack."
  fi

  if awk -v a="${avail}" -v w="${warn_at}" 'BEGIN { exit (a < w) ? 0 : 1 }'; then
    warn "Available memory ${avail} GiB is below E2E_MIN_MEM_GB=${warn_at}. Continuing; the stack may swap or time out."
  fi
}

ensure_docker() {
  if docker info >/dev/null 2>&1; then
    return 0
  fi

  say "Starting Docker daemon"
  if command -v service >/dev/null 2>&1; then
    sudo service docker start || true
  fi
  if ! docker info >/dev/null 2>&1; then
    sudo dockerd >/tmp/dockerd.log 2>&1 &
  fi

  local i
  for i in $(seq 1 60); do
    if docker info >/dev/null 2>&1; then
      say "Docker is ready"
      return 0
    fi
    sleep 1
  done
  die "Docker did not become ready. See /tmp/dockerd.log"
}

ensure_networks() {
  docker network inspect traefik-network >/dev/null 2>&1 || {
    say "Creating docker network traefik-network"
    docker network create traefik-network
  }
  docker network inspect mysql-network >/dev/null 2>&1 || {
    say "Creating docker network mysql-network"
    docker network create mysql-network
  }
}

# wait_http URL [max_seconds] [extra curl args...]
# Succeeds when HTTP status is 200.
wait_http() {
  local url="$1"
  local max="${2:-${E2E_WAIT_SECS}}"
  shift 2 || true
  local i status
  say "Waiting up to ${max}s for ${url}"
  for i in $(seq 1 "${max}"); do
    status="$(curl -sS -o /tmp/e2e-http-body -w '%{http_code}' "$@" "${url}" || true)"
    if [ "${status}" = "200" ]; then
      return 0
    fi
    sleep 1
  done
  die "${url} did not return 200 within ${max}s (last status: ${status:-none})"
}

wait_api_health() {
  local max="${1:-${E2E_WAIT_SECS}}"
  local i status body
  say "Waiting up to ${max}s for http://localhost:8000/api/health (db, redis, queue ok)"
  for i in $(seq 1 "${max}"); do
    status="$(curl -sS -o /tmp/e2e-health.json -w '%{http_code}' http://localhost:8000/api/health || true)"
    if [ "${status}" = "200" ] && command -v jq >/dev/null 2>&1; then
      body="$(cat /tmp/e2e-health.json)"
      if [ "$(echo "${body}" | jq -r '.status')" = "ok" ] \
        && [ "$(echo "${body}" | jq -r '.checks.db')" = "ok" ] \
        && [ "$(echo "${body}" | jq -r '.checks.redis')" = "ok" ] \
        && [ "$(echo "${body}" | jq -r '.checks.queue')" = "ok" ]; then
        return 0
      fi
    fi
    sleep 1
  done
  die "API health did not become ok within ${max}s. Last body: $(cat /tmp/e2e-health.json 2>/dev/null || echo none)"
}

wait_horizon() {
  local max="${1:-60}"
  local i
  say "Waiting up to ${max}s for Horizon"
  for i in $(seq 1 "${max}"); do
    if in_app "php artisan horizon:status" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  die "Horizon did not report running within ${max}s (queue mail will not send)"
}

env_value() {
  local file="$1"
  local key="$2"
  grep -E "^${key}=" "${file}" | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

app_key_empty() {
  local key
  key="$(env_value "${API_ROOT}/.env" APP_KEY || true)"
  [ -z "${key}" ]
}

copy_if_missing() {
  local src="$1"
  local dest="$2"
  if [ -e "${dest}" ]; then
    say "Keeping existing ${dest}"
    return 0
  fi
  say "Copying $(basename "${src}") → ${dest}"
  cp "${src}" "${dest}"
}

# up.sh rebuilds env files from the e2e environment unless E2E_KEEP_ENV=1.
install_env() {
  local src="$1"
  local dest="$2"
  if [ "${E2E_KEEP_ENV:-}" = "1" ]; then
    copy_if_missing "${src}" "${dest}"
    return 0
  fi
  say "Rebuilding ${dest} from $(basename "${src}")"
  cp "${src}" "${dest}"
}

print_heads() {
  local name dir sha subject
  for name in api ui panel engine portal; do
    case "${name}" in
      api) dir="${API_ROOT}" ;;
      ui) dir="${UI_DIR}" ;;
      panel) dir="${PANEL_DIR}" ;;
      engine) dir="${ENGINE_DIR}" ;;
      portal) dir="${PORTAL_DIR}" ;;
    esac
    sha="$(git -C "${dir}" rev-parse HEAD)"
    subject="$(git -C "${dir}" log -1 --format=%s)"
    say "HEAD ${name} ${sha} ${subject}"
  done
}

git_clone_url() {
  local repo="$1"
  if [ -n "${GH_TOKEN:-}" ]; then
    printf 'https://x-access-token:%s@github.com/iconic-project/%s.git' "${GH_TOKEN}" "${repo}"
  else
    printf 'https://github.com/iconic-project/%s.git' "${repo}"
  fi
}

ensure_sibling() {
  local name="$1"
  local ref="$2"
  local dest="${ICONIC_ROOT}/${name}"
  if [ -d "${dest}/.git" ]; then
    if [ -n "$(git -C "${dest}" status --porcelain)" ]; then
      die "Sibling ${name} at ${dest} has a dirty working tree. Commit, stash, or discard it before checking out ${ref}."
    fi
    say "Fetching ${name} and checking out ${ref}"
    git -C "${dest}" fetch origin --tags
    if git -C "${dest}" rev-parse --verify --quiet "refs/remotes/origin/${ref}" >/dev/null; then
      git -C "${dest}" checkout --detach "origin/${ref}"
    else
      git -C "${dest}" checkout --detach "${ref}"
    fi
    return 0
  fi
  if [ -d "${dest}" ]; then
    die "Sibling ${name} exists at ${dest} but is not a git checkout."
  fi
  say "Cloning ${name} (branch ${ref}) into ${dest}"
  git clone --branch "${ref}" --single-branch "$(git_clone_url "${name}")" "${dest}"
}

ensure_pnpm() {
  if ! command -v corepack >/dev/null 2>&1; then
    local nvm_bin
    for nvm_bin in \
      "${HOME}/.config/nvm/versions/node/v22.23.1/bin" \
      "${HOME}/.nvm/versions/node/v22.23.1/bin"; do
      if [ -x "${nvm_bin}/corepack" ]; then
        PATH="${nvm_bin}:${PATH}"
        export PATH
        break
      fi
    done
  fi

  if command -v corepack >/dev/null 2>&1; then
    corepack enable >/dev/null
    corepack prepare "pnpm@${PNPM_VERSION}" --activate
    say "pnpm $(pnpm --version) (via corepack)"
    return 0
  fi

  if command -v pnpm >/dev/null 2>&1; then
    say "pnpm $(pnpm --version) (existing; corepack not on PATH)"
    return 0
  fi

  die "corepack not found — install Node 22 with corepack, or put pnpm ${PNPM_VERSION} on PATH"
}

pnpm_frozen() {
  local dir="$1"
  if [ ! -d "${dir}" ]; then
    die "Missing checkout ${dir}"
  fi
  say "pnpm install --frozen-lockfile in ${dir}"
  (cd "${dir}" && pnpm install --frozen-lockfile)
}

port_in_use() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ss -ltn | grep -Eq ":${port}[[:space:]]"
  else
    curl -sS -o /dev/null --connect-timeout 1 "http://127.0.0.1:${port}/" || return 1
    return 0
  fi
}
