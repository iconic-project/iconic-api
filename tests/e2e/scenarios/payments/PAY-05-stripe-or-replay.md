# PAY-05 · Stripe test-mode (or fake-webhook replay) settles once
- **Tags:** sprint-5, payments
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A webhook must settle the deposit exactly once even if Stripe delivers it twice. Never reuse a seeded booking — create a fresh D2C reservation so the link is this run's.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/calendar`. Date range **Year 2027**. Confirm ANATIVA Suite 01 on `7 Nov 2027` shows `·`. If it does not, **stop**.
2. Open `/rms/reservations/bookings`. `＋ New reservation`. Type **CABIN (FIT / Group)**. Guest `E2E Pay05`, email `e2e.pay05@iconic.test`, phone `+1 555 0505`, preferred **EMAIL**. Main channel **D2C**, origin **Hotel Booking Engine**. Departure `7 Nov 2027` · ANATIVA. Adults `2`. Cabin **Suite 01**. Payment method for deposit stays **Card — payment link**. `Create reservation`. `Done`.
3. On the new booking (ANK-2026-0022) open **Payments**. If the success pane already created an OPEN deposit link, use it. Otherwise click `Create deposit link`.
4. **If Stripe test keys are configured:** pay the link with a Stripe test card (never live mode). **If keys are empty** (this stack): from `iconic-api` run `tests/e2e/bin/replay-stripe-checkout.sh ANK-2026-0022`. That posts `checkout.session.completed` twice with the same event id.
5. Refresh the booking. Read Overview and History.

## Expected
- [ ] E1 · After step 2: toast `Reservation ANK-2026-0022 created.` Status `PENDING PAYMENT`. Paid `USD 0`. Balance `USD 26,600`.
- [ ] E2 · An OPEN deposit link exists for `USD 2,660`.
- [ ] E3 · After pay or replay: status `CONFIRMED`. Paid `USD 2,660`. Balance `USD 23,940`. Ledger has one SETTLED deposit (`ANK-2026-0022-D01` or the reference the link created). A second identical webhook does not add a second row.
- [ ] E4 · History has `Payment recorded` for that reference and a System transition `Deposit settled · {reference}` (PENDING PAYMENT → CONFIRMED).
- [ ] E5 · The run report says which path ran: **Stripe test mode** or **replay-stripe-checkout.sh**.

## Notes
Do not run a live-mode Stripe payment. Target cabin is **7 Nov 2027 ANATIVA Suite 01** so this does not collide with ANAMARA seed claims. Next ANK after reset is 0022.
