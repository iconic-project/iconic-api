# HOFF-01 · A stay-window percent offer discounts the nights inside it
- **Tags:** sprint-22, hotel, offers
- **Priority:** P1
- **Batch:** B28
- **Users:** Carolina
- **Start:** reset

## Why
An offer applies to nights inside its stay window. Price-affecting offers stay pending until a Director approves them.

## Steps
1. Hotel seed. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/rms/booking-engine/offers`. Date range **All dates**.
2. Click **＋ New offer**. Code `E2EHOFF1`. Internal name `E2E two nights`. Benefit type **Percent off cabin rate**. Value `10`. Channel **D2C — public engine**.
3. Stay window: arrival `2026-12-21`, departure `2026-12-23`. Leave min nights empty. Leave every room type and rate plan unticked. Badge `TWO NIGHTS`. Tick the card badge and the price-calendar badge. Price line `Two nights −10%`. Click **Save** (not Save as draft).
4. Read the row status and the toast. Open the drawer and click **Approve**. Reason `E2E approve E2EHOFF1`. Submit.
5. Open `http://localhost:3001/rms/reservations/bookings`. Click **＋ New reservation**. Check-in `2026-12-21`, check-out `2026-12-25`. Room type **Standard Double**. Rate plan **Best available**. Adults `2`. Read the quote lines.

## Expected
- [ ] E1 · After save the status is **PENDING DIRECTOR**. Toast `Submitted — a Director must approve before this offer goes live.`
- [ ] E2 · After approve the status is **LIVE**. Toast `Offer approved — LIVE. The booking engine picks it up in < 30 seconds.` The reason was required.
- [ ] E3 · The reservation quote includes the line `Two nights −10%`. The stay is four Peak nights. The offer covers the first two only.

## Notes
21–23 Dec 2026 is the stay window (last night 22 Dec). 21–25 Dec is four nights, Monday–Thursday. Do not tick a room type or a rate plan: empty means all. Do not type a total; read the quote line.
