# DOC-06 · Remove billing email → BLOCKED; restore → resend arrives
- **Tags:** sprint-7, documents
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
With no usable address the delivery is BLOCKED with the reason, never skipped (J6). A later address must let a deliberate resend go out; the first-send idempotency key stays.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview** → **Billing** → `Edit billing`. Set Email `e2e.doc06@iconic.test`. Save.
2. **Documents**. Read reminder / pre-arrival rows — they must no longer be BLOCKED (expect `SCHEDULED` and `to e2e.doc06@iconic.test`).
3. **Billing** → `Edit billing`. Clear Email. Save.
4. **Documents**. Read those same rows.
5. Restore Email `e2e.doc06@iconic.test`. Save. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
6. On **Booking Confirmation & Invoice** click `Resend`. Confirm `Send Booking Confirmation & Invoice to e2e.doc06@iconic.test again?`
7. ```
   tests/e2e/bin/mail-find.sh --to e2e.doc06@iconic.test --subject "Booking confirmation & invoice — ANK-2026-0003" --sha256
   ```
   Compare sha256 to the stored invoice `file_sha256`.

## Expected
- [ ] E1 · After step 2: reminder 1, reminder 2 and pre-arrival are not `BLOCKED`. Status `SCHEDULED` (send dates still in the future). Recipient line `to e2e.doc06@iconic.test`. ⚠ UNVERIFIED — plan + Recipients after billing PATCH.
- [ ] E2 · After clearing Email: those rows (and any other due/scheduled row without a delivery) show `BLOCKED` and `No email address for the client of record` plus `Edit billing details`. Seeded SENT invoice / summary / receipt stay `SENT` (a delivery row already exists).
- [ ] E3 · After restore + Resend: toast `Document sent`. Mailpit has the invoice once. Attachment sha256 matches the stored file (resend attaches the issued PDF, never re-renders).
- [ ] E4 · db-check: more than one delivery for that document (`resend:{document_id}:{uuid}` plus the original `invoice:{document_id}`). The original key is unchanged.

## Notes
Issued SENT rows do not flip to BLOCKED when the email is removed — status comes from the last delivery. BLOCKED is for rows that still need to send.
