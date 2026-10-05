# CAMP-01 · OPENING-27 redeemed and revenue match the sold bookings, and a cancel drops both
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A campaign counts sold bookings that carry the offer code. It does not count a request, and it does not invent a discount.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/marketing/campaigns`.
2. On **OPENING-27**, **Create campaign**. Name `Opening 2027`, UTM `opening27`. Create.
3. Read Redeemed and Revenue. Independently count sold bookings (`CONFIRMED`, `FULLY_PAID`, `IN_HOUSE`, `CHECKED_OUT`, `OVERDUE`) whose `promo_code` or a price line is `OPENING-27`, and sum their charges.
4. If that count is 0, confirm one request that already carries the OPENING-27 line (a November engine request) so the booking is **CONFIRMED**. Read the card again.
5. Cancel that redeemed booking in the RMS with a reason. Reload the card.

## Expected
- [ ] E1 · Notice: `Offers are created and published in the RMS. The CRM measures what each campaign produced from the bookings that carry the offer code or the campaign's UTM key. It never creates a discount.`
- [ ] E2 · Redeemed and Revenue equal that sold set. A REQUESTED row with the line does not count. ⚠ UNVERIFIED — task 10 on a non-reset database saw 0 until ANK-R-2026-0044 was confirmed, then redeemed 1 and revenue USD 26,600.
- [ ] E3 · After cancel, redeemed and revenue drop by that booking.

## Notes
Do not compute ROAS in the panel. Null ROAS is `—`. Media spend left at 0 stays ROAS `—`.
