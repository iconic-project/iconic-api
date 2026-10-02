# PIPE-03 · Engine request opens Deposit pending; a confirmed deposit moves it to Booking confirmed
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Stages 5–7 are the booking. Sales does not drag them.

## Steps
1. **Guest.** Pay later on a free cabin (WEB-07), email `e2e.pipe03@iconic.test`. Read the request reference.
2. **Carolina.** Open `http://localhost:3001/crm/sales/pipeline`. Find the deal for that contact. It is opened by the queued `OpenDealOnBookingCreated` listener. If Horizon is down, that is **ENV**.
3. In the RMS, confirm the request and record the deposit the way WEB-08 does, until the booking is **CONFIRMED**.
4. Reload the pipeline. Open the same deal.

## Expected
- [ ] E1 · After the request the deal is **Deposit pending**, shows **LOCK**, and names the request reference.
- [ ] E2 · After the booking is **CONFIRMED** the deal is **Booking confirmed** and still locked. No Move to… for a stored stage.

## Notes
Do not drag the card. If the stage stays Deposit pending after CONFIRMED, that is a **BUG**.
