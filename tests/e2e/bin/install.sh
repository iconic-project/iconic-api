#!/usr/bin/env bash
# One-time, idempotent: sibling repos, pnpm deps, API images.
# Safe to re-run when Cursor prepares a machine image.
set -euo pipefail

# shellcheck source=./_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/_lib.sh"

say "install.sh  ICONIC_ROOT=${ICONIC_ROOT}  COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}"

ensure_docker
ensure_pnpm

ensure_sibling iconic-ui "${E2E_UI_REF:-dev}"
ensure_sibling iconic-panel "${E2E_PANEL_REF:-dev}"
ensure_sibling iconic-engine "${E2E_ENGINE_REF:-dev}"
ensure_sibling iconic-portal "${E2E_PORTAL_REF:-dev}"

pnpm_frozen "${UI_DIR}"
pnpm_frozen "${PANEL_DIR}"
pnpm_frozen "${ENGINE_DIR}"
pnpm_frozen "${PORTAL_DIR}"

# Compose interpolates MYSQL_* from .env. Copy e2e env if the developer has none.
copy_if_missing "${E2E_ENV_DIR}/api.env" "${API_ROOT}/.env"

say "docker compose pull (API images, missing only)"
# --policy missing keeps install idempotent and avoids hanging on :latest when images already exist.
if ! compose pull --policy missing; then
  warn "compose pull --policy missing failed; trying a full pull"
  compose pull || warn "compose pull failed — will use whatever images are already local"
fi

say "install.sh done (no database work — that happens in up.sh)"
