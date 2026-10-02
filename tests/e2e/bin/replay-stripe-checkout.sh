#!/usr/bin/env bash
# Replay checkout.session.completed (or --expired) for an OPEN payment link
# or an engine Checkout Session (FakeStripe / empty keys). Test mode only.
# Usage:
#   tests/e2e/bin/replay-stripe-checkout.sh ANK-2026-0022
#   tests/e2e/bin/replay-stripe-checkout.sh ANK-R-2026-0043
#   tests/e2e/bin/replay-stripe-checkout.sh --expired ANK-R-2026-0043
set -euo pipefail

# shellcheck source=./_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/_lib.sh"

expired=""
reference=""

for arg in "$@"; do
  case "${arg}" in
    --expired) expired=1 ;;
    -*) die "usage: replay-stripe-checkout.sh [--expired] <booking-or-request-reference>" ;;
    *) reference="${arg}" ;;
  esac
done

if [ -z "${reference}" ]; then
  die "usage: replay-stripe-checkout.sh [--expired] <booking-or-request-reference>"
fi

if [ -n "${expired}" ]; then
  say "iconic:replay-stripe-checkout --expired ${reference}"
  in_app "php artisan iconic:replay-stripe-checkout --expired $(printf '%q' "${reference}")"
else
  say "iconic:replay-stripe-checkout ${reference}"
  in_app "php artisan iconic:replay-stripe-checkout $(printf '%q' "${reference}")"
fi
