# DOC-01 · Documents tab on a seeded CONFIRMED booking
- **Tags:** sprint-7, documents
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The Documents tab is the plan the client-documents list also reads (J9). If a seeded CONFIRMED booking shows the wrong trigger, date or status, every later send and resend is reading a lie.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/reservations/bookings`. Date range **All dates**.
2. Open ANK-2026-0003 (Harrison & Whitfield). **Documents** tab.
3. Read every row: Document, `to {recipient}` or the BLOCKED reason, Trigger, Date, Status pill, and which of `Preview` / `Resend` / `Issue` / `Re-issue` are shown.

## Expected
- [ ] E1 · Note `Documents the system generates for this booking (§4.5). All client documents in English; PDFs follow the approved mockup v6.` Columns: Document · Trigger · Date · Status.
- [ ] E2 · **Booking Confirmation & Invoice** · trigger `Deposit verified → CONFIRMED` · date `2 Jul 2026` · status `SENT` · version `v1` · `Preview` and `Resend` shown. ⚠ UNVERIFIED — DemoDocumentsSeeder + plan builder, not a cloud reset.
- [ ] E3 · **Booking Summary (guest version)** · trigger `With the invoice` · date `2 Jul 2026` · status `SENT` · `Preview` and `Resend`. ⚠ UNVERIFIED — same source.
- [ ] E4 · **Payment Confirmation — Deposit** · trigger `Payment verified` · date `2 Jul 2026` · status `SENT` · `Preview` and `Resend`. ⚠ UNVERIFIED — same source.
- [ ] E5 · **Balance reminder 1 (21 days before due)** · trigger `Due 2027-07-10 − 21 days` · date `19 Jun 2027`. **Balance reminder 2 (7 days before due)** · trigger `Due 2027-07-10 − 7 days` · date `3 Jul 2027`. Status on both is `BLOCKED` with `No email address for the client of record` and link `Edit billing details` (seed emails fail RFC), **or** `SCHEDULED` / `to {email}` if a usable billing address is on the booking. Record which. ⚠ UNVERIFIED — plan dates from 0003 due `10 Jul 2027`; status from Recipients.
- [ ] E6 · **Pre-arrival information** · trigger `T−45` · date `23 Sep 2027`. Status `BLOCKED` (same email reason) or `SCHEDULED`. ⚠ UNVERIFIED — check-in `7 Nov 2027` − 45.
- [ ] E7 · **Guest preferences questionnaire** · trigger `T−45` · date `23 Sep 2027`. Status `BLOCKED` when no passenger has a usable email and the lead path has none, otherwise `SCHEDULED`. No Preview / Resend / Issue.
- [ ] E8 · **Transfer voucher** · trigger `T−7 · if contracted` · date `—` · status `NOT CONTRACTED`. 0003 has no transfer-triggering extra.
- [ ] E9 · **Final invoice** · trigger `Balance paid → FULLY PAID` · status `WAITING`.

## Notes
Seeded SENT is a delivery row written by `DemoDocumentsSeeder` without sending; Mailpit is empty after `reset.sh`. Invoice numbers (`INV-YYYY-NNNN`) — copy what the Preview header shows; do not invent them. Values: `fixtures/reference-values.md`.
