# WEB-08 · Pay deposit → replay completed → CONFIRMED

Retired in sprint 20. The engine searches stays. Walk HENG-01 through HENG-07. Do not walk these departure steps.
- **Tags:** sprint-8, web
- **Priority:** retired
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** Mailpit · two browser contexts

## Why
Money confirms (H7). Stripe is the authority; the e2e stack drives settlement with the replay helper, never a live card.

## Steps
1. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
2. **Guest.** Walkthrough to `/book/details` (2 adults, 7 Nov 2027 ANAMARA, Suite 03). Contact First `E2E`, Last `Web08`, Email `e2e.web08@iconic.test`, phone `+1 555 0808`, preferred **Email**.
3. Guests: nationality **United States**. Tick all four declarations (Terms, Cancellation, Privacy, Insurance).
4. **Option 2 · Instant confirmation.** `Pay deposit & confirm`. The browser may leave for a Stripe Checkout URL — do **not** enter card data.
5. From `iconic-api`:
   ```
   tests/e2e/bin/replay-stripe-checkout.sh ANK-R-2026-0043
   ```
   If the guest was redirected, the request reference is still `ANK-R-2026-0043` until settlement draws `ANK-`.
6. Guest: if still on Stripe, open `http://localhost:3000/book/confirmation` (or the `success_url`). Wait for the page to leave `Confirming your payment…`.
7. **Carolina.** Open the new `ANK-` booking (next after seed is **ANK-2026-0022** if 0043 converted). Read Overview and Documents.
8. ```
   tests/e2e/bin/mail-find.sh --to e2e.web08@iconic.test --subject "Booking confirmation & invoice"
   tests/e2e/bin/mail-find.sh --to e2e.web08@iconic.test --subject "Your Iconic booking summary"
   tests/e2e/bin/mail-find.sh --to e2e.web08@iconic.test --subject "Payment confirmation"
   ```

## Expected
- [ ] E1 · After replay: booking `CONFIRMED` with an `ANK-` reference (0043 is stored as `request_reference`). Paid equals the deposit on the last server quote. ⚠ UNVERIFIED — next ANK after reset is 0022 only if no other create ran.
- [ ] E2 · Confirmation screen `Deposit paid — booking confirmed` / `Your expedition is confirmed` (or it updates after poll). Never stay on `Confirming your payment…` after settlement. ⚠ UNVERIFIED — i18n `confirm.paid*`.
- [ ] E3 · Mailpit: invoice, summary and receipt **once each**. Subjects as in DOC-03 (`Booking confirmation & invoice — {ANK-}`, `Your Iconic booking summary — {ANK-}`, `Payment confirmation — {ANK-}`).
- [ ] E4 · A second identical replay (the helper already posts twice) does not add a second receipt.

## Notes
Never live-mode Stripe. Empty keys: FakeStripe + `replay-stripe-checkout.sh`. Record **replay** in the run report. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
