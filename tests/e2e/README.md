# E2E harness — agent entry point

Browser scenarios for a Cursor cloud agent. This does not replace Pest. Do not change application code, tests, seeders or scenarios during a run.

## 1. Start

```bash
tests/e2e/bin/up.sh
```

Wait until the last line is `ALL UP`. That includes the agent portal (not the staff panel) at `http://localhost:3002`. If it fails, write an **ENV** failure with the log (`tests/e2e/runs/.logs/` and compose logs) and stop.

`up.sh` is not the cloud `start` command. Always run it yourself and wait.

## 2. Accounts and URLs

Demo users: [`fixtures/accounts.md`](fixtures/accounts.md). Password for all four staff users: `password`. The portal login is separate: an agency user, not a staff role.

| What | URL |
|---|---|
| Panel | http://localhost:3001 |
| Engine | http://localhost:3000 |
| Portal | http://localhost:3002 |
| Mailpit | http://localhost:8025 |
| API docs | http://localhost:8000/docs/api |
| API health | http://localhost:8000/api/health |

## 3. Choosing scenarios

Catalogue: [`scenarios/INDEX.md`](scenarios/INDEX.md).

The portal is the agent site, not the staff panel. It listens on port **3002** (`http://localhost:3002`, sign-in at `http://localhost:3002/login`). Hotel portal scenarios are batch **B8**.

Pick by **id**, **tag** (`smoke`, `auth`, `users-roles`, `config`, `hotel`) or **priority** (P1 first). Yacht scenarios and the older product walks are in `scenarios/_archive/` and are not walked.

Example prompts: “Run the e2e smoke suite” → tag `smoke`. “Run all sprint-2 scenarios” → tag `sprint-2`.

## 4. For each scenario

1. Run `tests/e2e/bin/reset.sh` unless the scenario says **Start:** `continues from <ID>`.
2. Follow the steps exactly (URLs, field labels, values).
3. Check every **Expected** line (E1, E2, …).
4. Run any **Cross-checks**.

Helpers:

```bash
tests/e2e/bin/mail-latest.sh carolina@iconic.test
tests/e2e/bin/mail-find.sh --to e2e.doc03@iconic.test --subject "Booking confirmation & invoice" --sha256
tests/e2e/bin/db-check.sh 'App\Models\ChangeHistory::latest("id")->first()'
tests/e2e/bin/db-check.sh 'App\Models\User::query()->where("email","lucia@iconic.test")->first()->hasPermission(\App\Enums\Permission::BookingsDelete)'
tests/e2e/bin/status.sh
tests/e2e/bin/replay-stripe-checkout.sh ANK-2026-0022
tests/e2e/bin/replay-stripe-checkout.sh ANK-R-2026-0043
tests/e2e/bin/replay-stripe-checkout.sh --expired ANK-R-2026-0043
tests/e2e/bin/setup.sh portal-user AG-002
tests/e2e/bin/setup.sh portal-invite AG-001
tests/e2e/bin/setup.sh portal-suspend AG-001
tests/e2e/bin/setup.sh portal-resume AG-001
tests/e2e/bin/setup.sh agency-over-cap AG-002
tests/e2e/bin/setup.sh journey-due 1
tests/e2e/bin/setup.sh abandoned-checkout e2e.cart@iconic.test
tests/e2e/bin/setup.sh hard-bounce ANK-2026-0018
tests/e2e/bin/setup.sh inject-inbound-email whitfield.anna@iconic.test "Room question" "Is the twin free?"
tests/e2e/bin/setup.sh portal-pay ANK-R-2026-0043 DEPOSIT
```

`replay-stripe-checkout.sh` is the FakeStripe / empty-key path for PAY-05 (OPEN payment link) and WEB-08 / WEB-09 (engine Checkout Session). It posts `checkout.session.completed` twice (same event id), or `checkout.session.expired` with `--expired`. Test-mode cards only — never live mode.

`setup.sh` calls existing agency and portal actions inside the `app` container. `portal-user` prints an accepted login (`password`). `portal-invite` prints the Mailpit accept URL and does not store the token in a scenario. `portal-suspend` and `portal-resume` use the reasons `E2E portal suspend` and `E2E portal resume`. `agency-over-cap` reads the commission cap and raises the agency only when it is not already above it. The invite mail is queued: Horizon must be running.

`journey-due` sets that enrolment's `next_due_at` one minute in the past, runs `php artisan iconic:journeys` once, and prints status, position, branch, and the latest `template_version`. One call advances only steps that are already due after the move. `abandoned-checkout` accepts only `@iconic.test`. It turns Nurture on, captures the lead (which stitches the session and enrols the `lead` branch), records one `abandon_cart`, then runs `iconic:journeys` so the `abandoned_checkout` branch enrols. It refuses an address that already has a booking. `hard-bounce` takes that contact's email or an `ANK-` booking reference. Seeded A. Fontaine has no email, so pass `ANK-2026-0018`. It issues or reuses the invoice, queues a resend with the queue faked, and calls `SendDeliveryJob::handle` while `Mail::send` throws `550 5.1.1 user unknown`. Nothing is delivered. Horizon must not also send that delivery.

`inject-inbound-email` accepts only `@iconic.test`. It posts the message to Mailpit, then runs `PollInboxJob` once. It does not wait for the scheduler and it does not call Graph. It prints `conversation_id`, `contact_id` (null when unmatched), `unread`, and `message_id`. `portal-pay` calls `CreatePortalPaymentLink` as Ada (`ada@portal.test`) and prints `url` and `status`. A booking that is not AG-001 makes the helper die and creates no link. The reply mail from the inbox is queued: Horizon must be running before `mail-find.sh` can see it.

## 5. Agent rules

- **Observe, don't fix.** During an e2e run, never change application code, tests, seeders or scenarios. The only file you create is the run report.
- **One user per browser context.** Sign out, or use a fresh private context, before acting as another user. Scenarios with two users at once say so and use two separate contexts.
- **Evidence for every failure:**
  - a screenshot
  - the browser console errors
  - the failing network request (method, URL, status, response body, cookies not included)
  - the step number
- **Classify every failure:**
  - `BUG`: the product is wrong
  - `ENV`: the stack or machine is wrong
  - `SCENARIO`: the scenario is outdated or ambiguous; quote the line and propose new wording
  - `FLAKY`: passed on a retry; retry once only
- **Never loosen an expectation to make it pass.** If the product deliberately changed, it's a `SCENARIO` finding for a human to decide.
- **Expected values come from the scenario or `fixtures/reference-values.md`**, never from what the screen currently shows.
- **Time:** timestamps are shown in Galápagos time (UTC−6). Compare with that, not the machine's time zone.
- **Don't wait blindly.** Wait for a visible condition (the text, the toast, the row), at most 15 s, then fail the step.
- **Labels.** RMS fields use `label[for]` + control `id` (or `aria-label` on unlabeled table cells). Prefer `getByLabel` on Code, Max guests, Check-in, group-context, reason, and the stay fields. Status pills are CSS-uppercase (`INVITED`); match case-insensitively.
- **Engine analytics banner.** The engine shows a fixed bar at the bottom whenever analytics consent is unset, including when no GA measurement id is configured. It covers the lower edge of the page. On a fresh engine context, before any click, either click `Analytics off` or set `localStorage['iconic-engine-analytics']` to `refused` and reload. Use `accepted` only when the scenario is about behavioural events. Do not leave the bar up over footer actions.
- **`/api/auth/me`.** Call `http://localhost:8000/api/auth/me` (JSON, 401 when signed out). Do not open `/api/auth/me` as a panel URL — that is HTML from Nuxt, not the API.
- **Reports:**
  - write them to `runs/YYYY-MM-DD-HHMM-<slug>.md`
  - reports are not committed to `dev`; if the agent works on a branch, it may commit only its report there
  - always paste the summary table into the chat as well

## 6. Writing the report

Copy [`runs/_RUN_TEMPLATE.md`](runs/_RUN_TEMPLATE.md). Name the file `runs/YYYY-MM-DD-HHMM-<slug>.md`. Paste the summary table into the chat.

## 7. Stopping

```bash
tests/e2e/bin/down.sh
```

Volumes stay. `down.sh --wipe` deletes the `iconic-e2e` volumes only (refuses any other compose project).

## Cloud machine fallback

If `.cursor/environment.json` + the Dockerfile do not produce a usable Build, use **agent-driven setup** in the Cursor Cloud Agents dashboard. Tell the setup agent:

> Clone the five iconic repos as siblings, run `tests/e2e/bin/install.sh`, then `tests/e2e/bin/up.sh`, and confirm `tests/e2e/bin/status.sh` passes.

The only secret is `GH_TOKEN` (read access to `github.com/iconic-project`) if the repos are private. `api.env` holds test-only MySQL passwords. `APP_KEY` is generated at `up.sh` time.
