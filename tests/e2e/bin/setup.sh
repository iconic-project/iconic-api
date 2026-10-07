#!/usr/bin/env bash
# Portal setup for e2e scenarios. Existing actions only, inside the app container.
# Usage:
#   setup.sh portal-user <AG-reference>
#   setup.sh portal-invite <AG-reference>
#   setup.sh portal-suspend <AG-reference>
#   setup.sh portal-resume <AG-reference>
#   setup.sh agency-over-cap <AG-reference>
#   setup.sh journey-due <enrolment-id>
#   setup.sh abandoned-checkout <email@iconic.test>
#   setup.sh hard-bounce <email|ANK-reference>
#   setup.sh inject-inbound-email <email@iconic.test> <subject> <body>
#   setup.sh portal-pay <ANK-or-ANK-R-reference> <DEPOSIT|BALANCE>
set -euo pipefail

# shellcheck source=./_lib.sh
. "$(cd "$(dirname "$0")" && pwd)/_lib.sh"

PASSWORD="password"
ACTOR_EMAIL="carolina@iconic.test"

usage() {
  die "usage: setup.sh portal-user|portal-invite|portal-suspend|portal-resume|agency-over-cap <AG-reference> | journey-due <enrolment-id> | abandoned-checkout <email@iconic.test> | hard-bounce <email|ANK-reference> | inject-inbound-email <email@iconic.test> <subject> <body> | portal-pay <ANK-or-ANK-R-reference> <DEPOSIT|BALANCE>"
}

run_tinker() {
  local code="$1"
  local payload
  payload="$(printf '%s' "${code}" | base64 | tr -d '\n')"
  in_app "php artisan tinker --execute=\"eval(base64_decode('${payload}'));\""
}

json_line() {
  local output="$1"
  printf '%s\n' "${output}" | grep -E '^E2E_JSON:' | tail -n 1 | sed 's/^E2E_JSON://'
}

require_ref() {
  local ref="${1:-}"
  if ! printf '%s' "${ref}" | grep -Eq '^AG-[0-9]+$'; then
    die "agency must be a reference like AG-001"
  fi
  printf '%s' "${ref}"
}

portal_email() {
  local kind="$1"
  local ref="$2"
  printf '%s' "${kind}-$(printf '%s' "${ref}" | tr '[:upper:]' '[:lower:]')@portal.test"
}

# Shared prelude: $agency and $actor. Reference is interpolated only after require_ref.
php_prelude() {
  local ref="$1"
  cat <<PHP
\$agency = App\\Models\\Agency::query()->where('reference', '${ref}')->first();
if (! \$agency instanceof App\\Models\\Agency) {
    echo "E2E_JSON:".json_encode(['error' => 'unknown agency ${ref}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$actor = App\\Models\\User::query()->where('email', '${ACTOR_EMAIL}')->first();
if (! \$actor instanceof App\\Models\\User) {
    echo "E2E_JSON:".json_encode(['error' => 'missing ${ACTOR_EMAIL}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
PHP
}

emit_user() {
  cat <<'PHP'
echo "E2E_JSON:".json_encode([
    'email' => $user->email,
    'status' => $user->status->value,
    'accepted' => $user->accepted_at !== null,
], JSON_UNESCAPED_SLASHES)."\n";
PHP
}

ensure_user() {
  local ref="$1"
  local email="$2"
  local name="$3"
  php_prelude "${ref}"
  cat <<PHP
\$email = '${email}';
\$user = App\\Models\\AgencyUser::query()->where('email', \$email)->first();
if (\$user instanceof App\\Models\\AgencyUser && (int) \$user->agency_id !== (int) \$agency->id) {
    echo "E2E_JSON:".json_encode(['error' => 'email belongs to another agency'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
if (! \$user instanceof App\\Models\\AgencyUser) {
    app(App\\Actions\\Agencies\\CreateAgencyUser::class)->handle(\$agency, [
        'name' => '${name}',
        'email' => \$email,
    ], \$actor);
    \$user = App\\Models\\AgencyUser::query()->where('email', \$email)->first();
}
if (! \$user instanceof App\\Models\\AgencyUser) {
    echo "E2E_JSON:".json_encode(['error' => 'user was not created'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
PHP
}

invite_user() {
  cat <<'PHP'
if ($user->status === App\Enums\AgencyUserStatus::Disabled) {
    echo "E2E_JSON:".json_encode(['error' => 'user is disabled', 'email' => $user->email], JSON_UNESCAPED_SLASHES)."\n";
    return;
}
app(App\Actions\Agencies\InviteAgencyUser::class)->handle($user, $actor, false);
$user->refresh();
PHP
  emit_user
}

accept_from_mail() {
  local email="$1"
  local mail_out accept_url token
  say "Waiting for the portal invitation to ${email}"
  mail_out="$("${E2E_BIN_DIR}/mail-latest.sh" "${email}")"
  printf '%s\n' "${mail_out}"
  if ! printf '%s\n' "${mail_out}" | grep -q 'Subject: Set your Iconic portal password'; then
    die "Mailpit subject was not 'Set your Iconic portal password'. Horizon must be running so the invite job is sent."
  fi
  accept_url="$(printf '%s\n' "${mail_out}" | grep -oE 'https?://[^[:space:]]+/accept\?[^[:space:]]+' | head -n 1 || true)"
  if [ -z "${accept_url}" ]; then
    die "invitation mail had no /accept link"
  fi
  token="$(ACCEPT_URL="${accept_url}" python3 - <<'PY'
import os
from urllib.parse import parse_qs, urlparse
query = parse_qs(urlparse(os.environ["ACCEPT_URL"]).query)
print(query.get("token", [""])[0])
PY
)"
  if [ -z "${token}" ]; then
    die "invitation link had no token"
  fi
  local token_b64
  token_b64="$(printf '%s' "${token}" | base64 | tr -d '\n')"
  local code
  code="$(cat <<PHP
\$token = base64_decode('${token_b64}');
\$email = '${email}';
\$store = app('session')->driver();
\$store->start();
\$request = Illuminate\\Http\\Request::create('/api/portal/auth/accept', 'POST');
\$request->setLaravelSession(\$store);
app()->instance('request', \$request);
\$user = app(App\\Actions\\Portal\\AcceptAgencyInvitation::class)->handle(\$request, \$email, \$token, '${PASSWORD}');
echo "E2E_JSON:".json_encode([
    'email' => \$user->email,
    'status' => \$user->status->value,
    'accepted' => \$user->accepted_at !== null,
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  local output line
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ]; then
    die "accept did not return E2E_JSON"
  fi
  printf '%s\n' "${line}" | grep -q '"status":"ACTIVE"' || die "accept did not leave the user ACTIVE: ${line}"
}

append_php() {
  local base="$1"
  local extra="$2"
  printf '%s\n%s\n' "${base}" "${extra}"
}

cmd_portal_user() {
  local ref email name code output line status
  ref="$(require_ref "${1:-}")"
  email="$(portal_email "e2e-portal" "${ref}")"
  name="E2E Portal ${ref}"
  code="$(ensure_user "${ref}" "${email}" "${name}")"
  code="$(append_php "${code}" "$(cat <<'PHP'
if ($user->status === App\Enums\AgencyUserStatus::Active && $user->accepted_at !== null && $user->password !== null) {
    echo "E2E_JSON:".json_encode([
        'email' => $user->email,
        'status' => 'ACTIVE',
        'accepted' => true,
        'idempotent' => true,
    ], JSON_UNESCAPED_SLASHES)."\n";
    return;
}
PHP
)")"
  code="$(append_php "${code}" "$(invite_user)")"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ]; then
    die "portal-user did not return E2E_JSON"
  fi
  if printf '%s' "${line}" | grep -q '"error"'; then
    die "${line}"
  fi
  status="$(printf '%s' "${line}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("status",""))')"
  if [ "${status}" = "ACTIVE" ]; then
    printf 'email: %s\npassword: %s\nstatus: active\n' "${email}" "${PASSWORD}"
    return 0
  fi
  accept_from_mail "${email}"
  printf 'email: %s\npassword: %s\nstatus: active\n' "${email}" "${PASSWORD}"
}

cmd_portal_invite() {
  local ref email name code output line
  ref="$(require_ref "${1:-}")"
  email="$(portal_email "e2e-invite" "${ref}")"
  name="E2E Invite ${ref}"
  code="$(ensure_user "${ref}" "${email}" "${name}")"
  code="$(append_php "${code}" "$(cat <<'PHP'
if ($user->status === App\Enums\AgencyUserStatus::Active && $user->accepted_at !== null) {
    echo "E2E_JSON:".json_encode([
        'error' => 'that invite address is already active; reset the database for a fresh invitation',
        'email' => $user->email,
    ], JSON_UNESCAPED_SLASHES)."\n";
    return;
}
PHP
)")"
  code="$(append_php "${code}" "$(invite_user)")"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ]; then
    die "portal-invite did not return E2E_JSON"
  fi
  if printf '%s' "${line}" | grep -q '"error"'; then
    die "${line}"
  fi
  say "Waiting for the portal invitation to ${email}"
  local mail_out accept_url
  mail_out="$("${E2E_BIN_DIR}/mail-latest.sh" "${email}")"
  printf '%s\n' "${mail_out}"
  if ! printf '%s\n' "${mail_out}" | grep -q 'Subject: Set your Iconic portal password'; then
    die "Mailpit subject was not 'Set your Iconic portal password'. Horizon must be running so the invite job is sent."
  fi
  accept_url="$(printf '%s\n' "${mail_out}" | grep -oE 'https?://[^[:space:]]+/accept\?[^[:space:]]+' | head -n 1 || true)"
  if [ -z "${accept_url}" ]; then
    die "invitation mail had no /accept link"
  fi
  printf 'email: %s\nsubject: Set your Iconic portal password\naccept: %s\n' "${email}" "${accept_url}"
}

cmd_portal_access() {
  local action="$1"
  local ref="$2"
  local reason code output line
  ref="$(require_ref "${ref}")"
  if [ "${action}" = "suspend" ]; then
    reason="E2E portal suspend"
  else
    reason="E2E portal resume"
  fi
  code="$(php_prelude "${ref}")"
  if [ "${action}" = "suspend" ]; then
    code="$(append_php "${code}" "$(cat <<PHP
\$agency = app(App\\Actions\\Agencies\\SuspendAgencyPortal::class)->handle(\$agency, '${reason}', \$actor);
PHP
)")"
  else
    code="$(append_php "${code}" "$(cat <<PHP
\$agency = app(App\\Actions\\Agencies\\ResumeAgencyPortal::class)->handle(\$agency, '${reason}', \$actor);
PHP
)")"
  fi
  code="$(append_php "${code}" "$(cat <<'PHP'
echo "E2E_JSON:".json_encode([
    'reference' => $agency->reference,
    'suspended' => $agency->isPortalSuspended(),
], JSON_UNESCAPED_SLASHES)."\n";
PHP
)")"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "portal-${action} failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

cmd_agency_over_cap() {
  local ref code output line
  ref="$(require_ref "${1:-}")"
  code="$(php_prelude "${ref}")"
  code="$(append_php "${code}" "$(cat <<'PHP'
$cap = app(App\Services\Config\CurrentConfig::class)->businessRules()->commission->capPct;
$current = (int) $agency->commission_pct;
if ($current > $cap) {
    echo "E2E_JSON:".json_encode([
        'reference' => $agency->reference,
        'commission_pct' => $current,
        'cap_pct' => $cap,
        'changed' => false,
    ], JSON_UNESCAPED_SLASHES)."\n";
    return;
}
$next = $cap + 3;
app(App\Actions\Agencies\UpdateAgency::class)->handle($agency, ['commission_pct' => $next], $actor);
$agency->refresh();
echo "E2E_JSON:".json_encode([
    'reference' => $agency->reference,
    'commission_pct' => (int) $agency->commission_pct,
    'cap_pct' => $cap,
    'changed' => true,
], JSON_UNESCAPED_SLASHES)."\n";
PHP
)")"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "agency-over-cap failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

require_iconic_email() {
  local email="${1:-}"
  if ! printf '%s' "${email}" | grep -Eq '^[^@[:space:]]+@iconic\.test$'; then
    die "email must be @iconic.test"
  fi
  printf '%s' "${email}"
}

cmd_journey_due() {
  local id="$1"
  local code output line
  if ! printf '%s' "${id}" | grep -Eq '^[0-9]+$'; then
    die "enrolment must be a numeric id"
  fi
  code="$(cat <<PHP
\$enrolment = App\\Models\\JourneyEnrolment::query()->find(${id});
if (! \$enrolment instanceof App\\Models\\JourneyEnrolment) {
    echo "E2E_JSON:".json_encode(['error' => 'unknown enrolment ${id}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$enrolment->next_due_at = now()->subMinute();
\$enrolment->save();
echo "E2E_JSON:".json_encode(['id' => \$enrolment->id, 'moved' => true], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "journey-due failed: ${line:-no E2E_JSON}"
  fi
  in_app "php artisan iconic:journeys"
  code="$(cat <<PHP
\$enrolment = App\\Models\\JourneyEnrolment::query()->with('sends')->find(${id});
if (! \$enrolment instanceof App\\Models\\JourneyEnrolment) {
    echo "E2E_JSON:".json_encode(['error' => 'enrolment ${id} disappeared'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$send = \$enrolment->sends->sortByDesc('id')->first();
echo "E2E_JSON:".json_encode([
    'id' => \$enrolment->id,
    'status' => \$enrolment->status->value,
    'position' => \$enrolment->position,
    'branch' => \$enrolment->branch,
    'template_version' => \$send?->template_version,
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "journey-due read failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

cmd_abandoned_checkout() {
  local email first code output line
  email="$(require_iconic_email "${1:-}")"
  first="$(printf '%s' "${email}" | cut -d@ -f1 | tr -cd '[:alnum:]')"
  if [ -z "${first}" ]; then
    first="E2E"
  fi
  code="$(cat <<PHP
\$email = '${email}';
\$actor = App\\Models\\User::query()->where('email', '${ACTOR_EMAIL}')->first();
if (! \$actor instanceof App\\Models\\User) {
    echo "E2E_JSON:".json_encode(['error' => 'missing ${ACTOR_EMAIL}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$journey = App\\Models\\Journey::query()->where('key', 'nurture_to_request')->first();
if (! \$journey instanceof App\\Models\\Journey) {
    echo "E2E_JSON:".json_encode(['error' => 'missing nurture_to_request'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
app(App\\Actions\\Crm\\UpdateJourney::class)->handle(\$journey, true, \$actor);
\$version = app(App\\Services\\Config\\CurrentConfig::class)->businessRules()->consentVersions->checkoutMarketing;
\$session = (string) Illuminate\\Support\\Str::uuid();
app(App\\Actions\\Engine\\CaptureMarketingLead::class)->handle([
    'email' => \$email,
    'first_name' => '${first}',
    'version' => \$version,
    'session_id' => \$session,
], null);
\$contact = App\\Models\\Contact::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(\$email)])->first();
if (! \$contact instanceof App\\Models\\Contact) {
    echo "E2E_JSON:".json_encode(['error' => 'contact was not created'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
if (App\\Models\\Booking::query()->where('contact_id', \$contact->id)->exists()) {
    echo "E2E_JSON:".json_encode(['error' => 'contact already has a booking'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$departure = App\\Models\\Departure::query()->with('itinerary')->orderBy('id')->first();
if (! \$departure instanceof App\\Models\\Departure) {
    echo "E2E_JSON:".json_encode(['error' => 'no departure'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
app(App\\Actions\\Engine\\IngestBehaviouralEvents::class)->handle([
    'session_id' => \$session,
    'events' => [[
        'event_id' => (string) Illuminate\\Support\\Str::uuid(),
        'name' => 'abandon_cart',
        'occurred_at' => now()->toIso8601String(),
        'params' => [
            'itinerary_code' => (string) (\$departure->itinerary->code ?? 'WEST'),
            'departure_id' => \$departure->id,
            'step' => 'details',
            'cabin_count' => 1,
        ],
    ]],
]);
echo "E2E_JSON:".json_encode(['contact_id' => \$contact->id, 'session_id' => \$session], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "abandoned-checkout failed: ${line:-no E2E_JSON}"
  fi
  in_app "php artisan iconic:journeys"
  code="$(cat <<PHP
\$email = '${email}';
\$contact = App\\Models\\Contact::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(\$email)])->first();
if (! \$contact instanceof App\\Models\\Contact) {
    echo "E2E_JSON:".json_encode(['error' => 'contact missing after sweep'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$rows = App\\Models\\JourneyEnrolment::query()->where('contact_id', \$contact->id)->get();
echo "E2E_JSON:".json_encode([
    'contact_id' => \$contact->id,
    'enrolments' => \$rows->map(fn (\$row) => [
        'id' => \$row->id,
        'branch' => \$row->branch,
        'status' => \$row->status->value,
    ])->values(),
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "abandoned-checkout read failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

cmd_hard_bounce() {
  local arg="$1"
  local code output line
  if [ -z "${arg}" ]; then
    die "hard-bounce needs an email or an ANK- reference"
  fi
  if ! printf '%s' "${arg}" | grep -Eq '^ANK-'; then
    require_iconic_email "${arg}" >/dev/null
  fi
  code="$(cat <<PHP
\$arg = '${arg}';
\$booking = null;
\$contact = null;
if (str_starts_with(\$arg, 'ANK-')) {
    \$booking = App\\Models\\Booking::query()->where('reference', \$arg)->first();
    \$contact = \$booking?->contact;
} else {
    \$contact = App\\Models\\Contact::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim(\$arg))])->first();
    if (\$contact instanceof App\\Models\\Contact) {
        \$booking = App\\Models\\Booking::query()->where('contact_id', \$contact->id)->orderBy('id')->first();
    }
}
if (! \$contact instanceof App\\Models\\Contact || ! \$booking instanceof App\\Models\\Booking) {
    echo "E2E_JSON:".json_encode(['error' => 'contact with a booking was not found'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$actor = App\\Models\\User::query()->where('email', '${ACTOR_EMAIL}')->first();
if (! \$actor instanceof App\\Models\\User) {
    echo "E2E_JSON:".json_encode(['error' => 'missing ${ACTOR_EMAIL}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
Illuminate\\Support\\Facades\\Queue::fake();
\$document = App\\Models\\Document::query()
    ->where('booking_id', \$booking->id)
    ->where('kind', App\\Enums\\DocumentKind::Invoice)
    ->orderByDesc('id')
    ->first();
if (! \$document instanceof App\\Models\\Document) {
    \$document = app(App\\Actions\\Documents\\PrepareIssueDocument::class)->handle(
        \$booking,
        App\\Enums\\DocumentKind::Invoice,
        null,
        null,
        \$actor,
    );
}
\$delivery = app(App\\Actions\\Documents\\SendDocument::class)->handle(\$booking, \$document, \$actor, false, true);
\$manager = app('mail.manager');
\$manager->extend('e2e-hard-bounce', function () {
    return new class implements Illuminate\\Contracts\\Mail\\Mailer {
        public function to(\$users): Illuminate\\Mail\\PendingMail
        {
            throw new RuntimeException('unused');
        }
        public function cc(\$users): Illuminate\\Mail\\PendingMail
        {
            throw new RuntimeException('unused');
        }
        public function bcc(\$users): Illuminate\\Mail\\PendingMail
        {
            throw new RuntimeException('unused');
        }
        public function raw(\$text, \$callback): ?Illuminate\\Mail\\SentMessage
        {
            throw new RuntimeException('unused');
        }
        public function send(\$view, array \$data = [], \$callback = null): ?Illuminate\\Mail\\SentMessage
        {
            throw new Symfony\\Component\\Mailer\\Exception\\TransportException('550 5.1.1 user unknown');
        }
        public function sendNow(\$mailable, array \$data = [], \$callback = null): ?Illuminate\\Mail\\SentMessage
        {
            throw new Symfony\\Component\\Mailer\\Exception\\TransportException('550 5.1.1 user unknown');
        }
    };
});
\$manager->setDefaultDriver('e2e-hard-bounce');
(new App\\Jobs\\SendDeliveryJob(\$delivery->id))->handle(app(App\\Support\\Automations\\AutomationGate::class));
\$delivery->refresh();
\$suppressed = App\\Models\\ChangeHistory::query()
    ->where('subject_type', \$contact->getMorphClass())
    ->where('subject_id', \$contact->id)
    ->where('event', 'contact.suppressed')
    ->where('reason', 'HARD_BOUNCE')
    ->exists();
echo "E2E_JSON:".json_encode([
    'contact_id' => \$contact->id,
    'booking' => \$booking->reference,
    'delivery_id' => \$delivery->id,
    'status' => \$delivery->status->value,
    'contact_suppressed' => \$suppressed,
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "hard-bounce failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}" | grep -q '"status":"HARD_BOUNCE"' || die "hard-bounce did not record HARD_BOUNCE: ${line}"
  printf '%s\n' "${line}"
}

cmd_inject_inbound_email() {
  local email subject body subject_b64 body_b64 code output line
  email="$(require_iconic_email "${1:-}")"
  subject="${2:-}"
  body="${3:-}"
  if [ -z "${subject}" ] || [ -z "${body}" ]; then
    die "usage: setup.sh inject-inbound-email <email@iconic.test> <subject> <body>"
  fi
  subject_b64="$(printf '%s' "${subject}" | base64 | tr -d '\n')"
  body_b64="$(printf '%s' "${body}" | base64 | tr -d '\n')"
  code="$(cat <<PHP
\$email = '${email}';
\$subject = base64_decode('${subject_b64}');
\$body = base64_decode('${body_b64}');
\$to = (string) config('mail.from.address');
if (\$to === '') {
    \$to = 'inbox@iconic.test';
}
\$base = rtrim((string) config('iconic.inbox.mailpit_url'), '/');
Illuminate\\Support\\Facades\\Http::acceptJson()->post(\$base.'/api/v1/send', [
    'From' => ['Email' => \$email, 'Name' => \$email],
    'To' => [['Email' => \$to]],
    'Subject' => \$subject,
    'Text' => \$body,
])->throw();
app(App\\Jobs\\PollInboxJob::class)->handle(
    app(App\\Support\\Mail\\MailboxReader::class),
    app(App\\Actions\\Crm\\CaptureInboundMessage::class),
);
\$message = App\\Models\\Message::query()
    ->where('from', \$email)
    ->where('subject', \$subject)
    ->latest('id')
    ->first();
if (! \$message instanceof App\\Models\\Message) {
    echo "E2E_JSON:".json_encode(['error' => 'injected message was not stored'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$message->load('conversation');
echo "E2E_JSON:".json_encode([
    'conversation_id' => \$message->conversation_id,
    'contact_id' => \$message->conversation->contact_id,
    'unread' => (bool) \$message->conversation->unread,
    'message_id' => \$message->message_id,
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "inject-inbound-email failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

cmd_portal_pay() {
  local reference kind code output line
  reference="${1:-}"
  kind="${2:-}"
  if ! printf '%s' "${reference}" | grep -Eq '^(ANK|HTL)-(R-)?[0-9]{4}-[0-9]+$'; then
    die "reference must look like HTL-2026-0007, ANK-2026-0007 or ANK-R-2026-0043"
  fi
  if ! printf '%s' "${kind}" | grep -Eq '^(DEPOSIT|BALANCE)$'; then
    die "kind must be DEPOSIT or BALANCE"
  fi
  code="$(cat <<PHP
\$reference = '${reference}';
\$kind = '${kind}';
\$actor = App\\Models\\AgencyUser::query()->where('email', 'ada@portal.test')->first();
if (! \$actor instanceof App\\Models\\AgencyUser) {
    echo "E2E_JSON:".json_encode(['error' => 'missing ada@portal.test'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
\$booking = App\\Models\\Booking::query()
    ->where('reference', \$reference)
    ->orWhere('request_reference', \$reference)
    ->first();
if (! \$booking instanceof App\\Models\\Booking) {
    echo "E2E_JSON:".json_encode(['error' => 'unknown reference ${reference}'], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
try {
    \$link = app(App\\Actions\\Payments\\CreatePortalPaymentLink::class)->handle(\$booking, ['kind' => \$kind], \$actor);
} catch (Illuminate\\Auth\\Access\\AuthorizationException \$exception) {
    echo "E2E_JSON:".json_encode(['error' => \$exception->getMessage()], JSON_UNESCAPED_SLASHES)."\\n";
    return;
}
echo "E2E_JSON:".json_encode([
    'reference' => \$booking->reference ?? \$booking->request_reference,
    'url' => \$link->url,
    'status' => \$link->status->value,
    'id' => \$link->id,
], JSON_UNESCAPED_SLASHES)."\\n";
PHP
)"
  output="$(run_tinker "${code}")"
  printf '%s\n' "${output}"
  line="$(json_line "${output}")"
  if [ -z "${line}" ] || printf '%s' "${line}" | grep -q '"error"'; then
    die "portal-pay failed: ${line:-no E2E_JSON}"
  fi
  printf '%s\n' "${line}"
}

command="${1:-}"
shift || true

case "${command}" in
  portal-user) cmd_portal_user "${1:-}" ;;
  portal-invite) cmd_portal_invite "${1:-}" ;;
  portal-suspend) cmd_portal_access suspend "${1:-}" ;;
  portal-resume) cmd_portal_access resume "${1:-}" ;;
  agency-over-cap) cmd_agency_over_cap "${1:-}" ;;
  journey-due) cmd_journey_due "${1:-}" ;;
  abandoned-checkout) cmd_abandoned_checkout "${1:-}" ;;
  hard-bounce) cmd_hard_bounce "${1:-}" ;;
  inject-inbound-email) cmd_inject_inbound_email "${1:-}" "${2:-}" "${3:-}" ;;
  portal-pay) cmd_portal_pay "${1:-}" "${2:-}" ;;
  *) usage ;;
esac
