# DOC-03 · Mark wire received → invoice, summary and receipt in Mailpit
- **Tags:** sprint-7, documents
- **Priority:** P1
- **Users:** Carolina then cfo@iconic.test
- **Start:** reset
- **Needs:** Mailpit

## Why
Reaching CONFIRMED must issue and send the invoice and booking summary, and the settled deposit must send one receipt (J7). A replayed mark-received must not send twice.

## Steps
1. Sign in as Carolina. Open ANK-2026-0014 (R. Ellison). **Overview** → **Billing** → `Edit billing`. Set Email `e2e.doc03@iconic.test`. Save. Toast `Billing details updated`.
2. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
3. Sign out. Sign in as `cfo@iconic.test` / `password`. Open 0014 → **Payments** (or `/rms/commercial/payments`). On `ANK-2026-0014-D01` click `Mark received`. Title `Wire received — bank reference`. Bank reference `SWIFT-PAY03`. Submit.
4. Wait for the header `CONFIRMED`. Open **Documents**.
5. Run:
   ```
   tests/e2e/bin/mail-find.sh --to e2e.doc03@iconic.test --subject "Booking confirmation & invoice — ANK-2026-0014" --sha256
   tests/e2e/bin/mail-find.sh --to e2e.doc03@iconic.test --subject "Your Iconic booking summary — ANK-2026-0014"
   tests/e2e/bin/mail-find.sh --to e2e.doc03@iconic.test --subject "Payment confirmation — ANK-2026-0014"
   ```
6. Cross-check the invoice attachment sha256:
   ```
   tests/e2e/bin/db-check.sh 'App\Models\Document::query()->where("booking_id", App\Models\Booking::query()->where("reference","ANK-2026-0014")->value("id"))->where("kind","INVOICE")->latest("id")->value("file_sha256")'
   ```

## Expected
- [ ] E1 · After mark received: toast `Wire marked received`. Header `ANK-2026-0014 · CONFIRMED`. Paid `USD 2,660`. Balance `USD 23,940`. Ledger `SETTLED`.
- [ ] E2 · Documents: invoice, summary and the deposit receipt are `SENT` (or `QUEUED` then `SENT` after Horizon). Each appears **once**.
- [ ] E3 · Mailpit has exactly one message for each of the three subjects. Invoice mail lists a PDF attachment. ⚠ UNVERIFIED — subjects from DeliverySubject; wait up to 30s for Horizon.
- [ ] E4 · Invoice attachment sha256 equals the stored `file_sha256`. The email carries the issued file, not a re-render.
- [ ] E5 · A second search finds no duplicate subjects.

## Notes
One user per browser context — sign out before CFO. Seed contact emails fail RFC; Billing must be set or the three rows stay BLOCKED and Mailpit stays empty (classify **SCENARIO** if the screen contradicts this). `mail-find.sh` is the Task 08 helper; do not send anywhere but Mailpit.
