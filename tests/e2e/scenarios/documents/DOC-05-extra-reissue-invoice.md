# DOC-05 · Add an extra → invoice v2 emailed; v1 unchanged
- **Tags:** sprint-7, documents
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
An issued invoice is immutable (J2). Adding a charge after issue must create version 2 with a reason and leave v1 downloadable as it was issued.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview** → **Billing** → `Edit billing`. Set Email `e2e.doc05@iconic.test`. Save.
2. **Documents**: note invoice `v1`. **Preview** it and record Stay charges / Other services / INVOICE TOTAL (expect `26,600.00` / `0.00` / `USD 26,600.00`). Close.
3. **Extras** tab. Confirm the notice `This booking already has an invoice — changes here re-issue an updated invoice to the client.`
4. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
5. Under **Add a service**, Service `Domestic flights GYE/UIO ↔ SCY (round-trip)`. Qty `2`. Rate (USD) `420`. `Add to booking`.
6. Re-open **Documents**. Preview **v2**. Open the earlier-version link for **v1**.
7. ```
   tests/e2e/bin/mail-find.sh --to e2e.doc05@iconic.test --subject "Booking confirmation & invoice — ANK-2026-0003"
   ```

## Expected
- [ ] E1 · Extras notice is visible before the add (0003 already has a seeded invoice `document_id`).
- [ ] E2 · Toast `Extra added`. Documents invoice row shows `v2 · Extra added — Domestic flights GYE/UIO ↔ SCY (round-trip) × 2` and status `SENT` (or QUEUED then SENT). ⚠ UNVERIFIED — BookingChargesChanged reason + SendOnBookingChargesChanged.
- [ ] E3 · v2 preview: Other services `840.00`, INVOICE TOTAL `USD 27,440.00`. Stay charges still `26,600.00`.
- [ ] E4 · v1 preview still shows Other services `0.00` and `USD 26,600.00` — the issued snapshot, not today’s charges.
- [ ] E5 · Mailpit: one invoice mail after the add (v2). Attachment sha256 matches the **latest** invoice row’s `file_sha256`, not v1.

## Notes
Same extra as EXT-01. Deposit stays `USD 2,660`. Do not loosen E4 if v1 has been rewritten — that is a **BUG**.
