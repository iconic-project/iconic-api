# DOC-08 · Wire instructions: LEG-004 warning and placeholder PDF
- **Tags:** sprint-7, documents
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Bank details are PENDING CLIENT (LEG-004). Sending wire instructions must warn staff and mark the PDF as placeholders so nobody wires to `[TBD]`.

## Steps
1. Sign in as Carolina. Open ANK-2026-0014 (R. Ellison) — status `PENDING PAYMENT`. **Overview** → **Billing** → set Email `e2e.doc08@iconic.test`. Save.
2. **Payments**. Confirm the **Wire instructions** block is shown. Click `Preview` on it (or Preview `WIRE_INSTRUCTIONS` from Documents after send). Read the bank block.
3. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
4. Click `Send wire instructions`. Confirm `Email wire instructions to the client?`
5. Read the warnbox and `Sent to {to}`.
6. ```
   tests/e2e/bin/mail-find.sh --to e2e.doc08@iconic.test --subject "Wire transfer instructions — ANK-2026-0014" --sha256
   ```
7. **Documents**: the Wire Instructions row is now listed. Preview it. Confirm placeholders.

## Expected
- [ ] E1 · After send: toast `Wire instructions sent`. `Sent to e2e.doc08@iconic.test`. Warnbox exact string `Bank details are placeholders (LEG-004) and must not be used.`
- [ ] E2 · Mailpit: one message with that subject and a PDF attachment. sha256 equals the stored wire-instructions `file_sha256`.
- [ ] E3 · Preview / snapshot: every bank field is `[TBD]`. The document states the details must not be used (`placeholders: true`). Payment reference includes `ANK-2026-0014`. Amount is the deposit `USD 2,660` (PENDING_PAYMENT uses `depositAmount()`). ⚠ UNVERIFIED — WireInstructionsSnapshot + task 02.
- [ ] E4 · 0014 stays `PENDING PAYMENT`. Paid stays `USD 0`. This send does not settle the wire.

## Notes
Wire instructions appear on the plan only once issued (J9). `payments.record` is required. Do not invent bank values.
