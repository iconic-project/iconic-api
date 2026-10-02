# PAY-11 · Trade channel at 15 %: cap warning, hold, deposit does not confirm
- **Tags:** sprint-5, payments
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A commission above the 12 % cap must create `ON_HOLD_AGENCY`. A settled deposit must not confirm it until a director approves the rate (FIN-005 / H8).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. `＋ New reservation`. Type **CABIN (FIT / Group)**. Guest `E2E Pay11`, email `e2e.pay11@iconic.test`, phone `+1 555 0511`, preferred **EMAIL**.
2. Main channel **B2B**. Origin **Travel Advisor**. Agent / Agency `Meridian Voyages — ILTM · >12%`. Commission % `15`. Read the cap warning.
3. Departure `7 Nov 2027` · ANATIVA. Adults `2`. Cabin **Suite 04**. Payment method for deposit **Wire transfer (72h · PENDING_PAYMENT)** (or Card if preferred — then record a deposit on the Payments tab in step 5). `Create reservation`. `Done`.
4. Read Overview. If the deposit is not yet on the ledger, open **Payments** and record Type `Deposit`, Method `Card (Stripe)`, Amount `2660`. `Record payment`.
5. Still on Overview, click `Approve commission`. Modal `Approve commission above the cap`. Reason `E2E approve 15`. `Record`.

## Expected
- [ ] E1 · Before create: warning `Commission above 12% is blocked (FIN-005). The booking is created and holds its cabin, but stays ON_HOLD_AGENCY and cannot be confirmed until someone with commissions.override_cap approves it.` (or the form's `Commission above 12% is allowed here. Bookings at that rate will be held for Director approval (FIN-005).`). Create stays enabled.
- [ ] E2 · Success / toast `Reservation created but HELD: commission 15% exceeds the 12% cap (FIN-005). It cannot reach CONFIRMED until the Commercial Director approves. Alert sent.` Reference is `ANK-2026-0022` (or the next free ANK if 0022 was taken). Status `ON HOLD AGENCY`.
- [ ] E3 · Overview: Agency `Meridian Voyages · 15% · awaiting Director approval`. Warnbox `Commission above cap (FIN-005)` / `HELD — commission 15% above the 12% cap. CONFIRMED is blocked until a director approves the rate.` Buttons `Approve commission` and `Reject commission`.
- [ ] E4 · After the settled deposit, status is still `ON HOLD AGENCY`. Paid `USD 2,660`. History may include `Deposit settled — CONFIRMED blocked: commission 15 % above the 12 % cap (FIN-005)` as System.
- [ ] E5 · After approve: toast `Commission approved`. Hold notice clears. Status becomes `CONFIRMED` (deposit already settled). History records the approval under Carolina.

## Notes
ANATIVA 7 Nov Suite 04 is free after reset (seed 0021 occupies ANAMARA Suite 01 on 14 Nov). Do not use a D2C channel — Agency fields hide. Cap 12 and default 10 come from business rules, never from a constant in the script.
