# CAMP-02 · A UTM booking counts as first touch once it is sold
- **Tags:** sprint-10, crm
- **Priority:** P2
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** CAMP-01’s campaign (`utm_campaign` `opening27`)

## Why
Attribution reads the frozen UTM on a sold booking. A request is not sold yet.

## Steps
1. Read Attributed first touch on **Opening 2027**.
2. **Guest.** Open the engine with `?utm_campaign=opening27` and pay later on a free stay (HENG-03). Email `e2e.camp02@iconic.test`.
3. **Carolina.** Reload the campaign. Read first touch.
4. Confirm that request in the RMS so the booking is **CONFIRMED**. Reload the campaign.

## Expected
- [ ] E1 · While the booking is **REQUESTED**, first touch is unchanged.
- [ ] E2 · After **CONFIRMED**, first touch count is one higher and the revenue includes that booking’s charges.

## Notes
The engine on port 3000 may be down. `POST /api/engine/checkout` with `attribution.first_touch.campaign` = `opening27` is the same write. A request that increments the count is a **BUG** against `CampaignMeasures` (sold statuses only). Do not change the measure to make a request count.
