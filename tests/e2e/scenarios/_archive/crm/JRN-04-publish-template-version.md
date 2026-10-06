# JRN-04 · A published template version is what the next send uses
- **Tags:** sprint-14, crm
- **Priority:** P1
- **Batch:** B19
- **Users:** Carolina
- **Start:** reset

## Why
Preview and a test send do not publish. The next real send uses the version that was published, including its approval reference.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/journeys?template=welcome_web_lead`.
2. Find a contact: search **Anna Whitfield**. **Preview**.
3. **Send test to me**.
4. **Create draft**. Subject `Your Galápagos adventure begins here — Iconic`. One paragraph that includes `{{unsubscribe_link}}`. **Create draft**.
5. Approval reference `E2E-JRN-04`. **Publish**.
6. Open `?template=request_acknowledgement`. Create a draft whose paragraph includes `{{unsubscribe_link}}`. Publish with approval `E2E-JRN-04-tx`.
7. Turn **Nurture to Request — D2C** on. Run `tests/e2e/bin/setup.sh abandoned-checkout e2e.jrn04@iconic.test`.

## Expected
- [ ] E1 · Preview shows subject `Your Galápagos adventure begins here — Iconic`. It does not send.
- [ ] E2 · Test send toast `Test sent: Your Galápagos adventure begins here — Iconic` (or the subject the API returns). Mailpit delivers it only to `carolina@iconic.test`.
- [ ] E3 · Draft toast `Draft created`. Publish toast `Template published`. The published row is version 2.
- [ ] E4 · The transactional publish is refused with `A transactional template must not include {{unsubscribe_link}}.`
- [ ] E5 · The nurture send from step 7 stores `template_version` 2.

## Cross-checks
- `bin/db-check.sh 'App\Models\JourneySend::query()->where("template_key","welcome_web_lead")->latest("id")->value("template_version")'` → `2`.

## Notes
Do not send the test to any address except the signed-in staff user. The helper's runner is what performs the real send in step 7.
