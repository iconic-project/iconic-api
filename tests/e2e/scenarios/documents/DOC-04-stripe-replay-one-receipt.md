# DOC-04 · Stripe replay twice → one receipt email
- **Tags:** sprint-7, documents
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset
- **Needs:** Mailpit

## Why
Doc 07 rule 6 / J5: a replayed `payment.received` must not send a second receipt. The deposit that confirms the booking still sends invoice + summary once.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Confirm ANATIVA Suite 01 on `7 Nov 2027` shows `·`. If it does not, **stop**.
2. Open `/rms/reservations/bookings`. `＋ New reservation`. Type **CABIN (FIT / Group)**. Guest `E2E Doc04`, email `e2e.doc04@iconic.test`, phone `+1 555 0404`, preferred **EMAIL**. Main channel **D2C**, origin **Hotel Booking Engine**. Departure `7 Nov 2027` · ANATIVA. Adults `2`. Cabin **Suite 01**. Payment method for deposit stays **Card — payment link**. `Create reservation`. `Done`.
3. On the new booking (ANK-2026-0022) open **Payments**. If no OPEN deposit link, click `Create deposit link`.
4. Clear Mailpit: `curl -sS -X DELETE http://localhost:8025/api/v1/messages`.
5. From `iconic-api` run `tests/e2e/bin/replay-stripe-checkout.sh ANK-2026-0022` (posts `checkout.session.completed` twice, same event id). If Stripe test keys are configured, pay once with a test card instead and do **not** use live mode — then still do not expect a second receipt from a replay of the same event.
6. Refresh the booking. Read Overview, **Documents**, and Mailpit:
   ```
   tests/e2e/bin/mail-find.sh --to e2e.doc04@iconic.test --subject "Payment confirmation — ANK-2026-0022"
   tests/e2e/bin/mail-find.sh --to e2e.doc04@iconic.test --subject "Booking confirmation & invoice — ANK-2026-0022"
   tests/e2e/bin/mail-find.sh --to e2e.doc04@iconic.test --subject "Your Iconic booking summary — ANK-2026-0022"
   ```

## Expected
- [ ] E1 · After create: toast `Reservation ANK-2026-0022 created.` Status `PENDING PAYMENT`. An OPEN deposit link for `USD 2,660`.
- [ ] E2 · After replay: status `CONFIRMED`. Paid `USD 2,660`. Balance `USD 23,940`. One SETTLED deposit row. A second identical webhook does not add a second ledger row (PAY-05).
- [ ] E3 · Mailpit: **one** `Payment confirmation — ANK-2026-0022`, not two. One invoice and one summary. ⚠ UNVERIFIED — Horizon + DeliveryKey `receipt:{payment_id}`.
- [ ] E4 · Documents tab: invoice, summary and the deposit receipt `SENT` once each.

## Notes
Next ANK after reset is 0022 (same cabin as PAY-05). Guest email is RFC-valid so Recipients can send. Empty Stripe keys: FakeStripe + `replay-stripe-checkout.sh`. Record which path ran.
