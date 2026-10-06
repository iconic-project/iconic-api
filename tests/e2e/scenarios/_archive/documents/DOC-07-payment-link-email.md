# DOC-07 · Send a payment link by email
- **Tags:** sprint-7, documents
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Staff can email a payment link from the Payments tab. The message must carry the link and the amount — not a blank “please pay”.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview** → **Billing** → set Email `e2e.doc07@iconic.test`. Save.
2. **Payments** tab. Under **Payment link** click `Create balance link` (the deposit is already settled).
3. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
4. On the OPEN link click `Send by email`. Confirm `Email this payment link to the client?`
5. ```
   tests/e2e/bin/mail-find.sh --to e2e.doc07@iconic.test --subject "Pay your Iconic balance — ANK-2026-0003"
   ```
   Read subject, To, and every http(s) link (or the body amount).

## Expected
- [ ] E1 · Hint reads `Copy the link, or send it by email below.` (Sprint 7 copy). Toast `Payment link created`. OPEN balance link for `USD 23,940`.
- [ ] E2 · After send: toast `Payment link sent`. Tab shows `Last payment-link email: SENT to e2e.doc07@iconic.test · {date}` (or `QUEUED` then `SENT`). Recipient after send is the 201 `Delivery.to`. ⚠ UNVERIFIED — i18n `payments.lastLinkEmail`.
- [ ] E3 · Mailpit subject `Pay your Iconic balance — ANK-2026-0003`. Body includes the amount (`USD 23,940` or `23,940`) and an http(s) **Complete your reservation** URL (`{ENGINE_URL}/complete/{token}`). The Stripe Payment Link URL is not in the email.
- [ ] E4 · Settled `ANK-2026-0003-D01` is untouched. A `receipt` control remains on that row.

## Notes
0003 deposit is already paid — use the **balance** link. If create fails with empty Stripe `api_key`, classify **ENV** (same as PAY-04). FakeStripe still creates a local OPEN link when keys are empty and `APP_ENV` is local.
