# 22-02 — Buyout (ex-charter): implement or retire

**Repo:** iconic-api, iconic-panel, iconic-engine
**Depends on:** client answer to **HQ1**
**Read first:** 09 H14, HQ1; `Actions/Charter/*`, `Support/Charter/*`, `Enums/Charter*.php`, `BookingType::Charter`, business rules `charter`, engine settings `charter`, engine `charter.vue`, `charter-proposal/[token].vue`, `CharterDepositsCommand.php`

**Stop and ask** if HQ1 is not answered in `09` or `08` when this task starts. Do not choose.

## Path A — client keeps exclusive use
1. `BookingType::Charter` → `Buyout` (value migration). A buyout claims **every active room** of the property for the stay via `ClaimService::claim` (single transaction; any conflict → 409 listing conflicting nights/rooms).
2. Pricing: rates v2 gains `buyout: {nightly_by_season: {SEASON: int}, min_nights}`; `RoomPricer::quoteBuyout`. Deposit/cancellation from a dedicated rate plan (`BUYOUT`) using cancellation set `CHARTER` (renamed `BUYOUT`).
3. Enquiry → proposal → accept flow kept (it is good); copy and fields made property-generic (group contexts list stays in engine settings).
4. Engine page `buyout.vue` (replaces `charter.vue`), gated by engine setting `buyout.enabled`.
5. New scenario **HBUY-01**.

## Path B — client drops it
1. Engine settings `buyout.enabled = false` (from 09 H14) already hides it; now remove routes (`charter-enquiries`, `charter-proposal/*` → 410), Actions, enums, commands, business-rules and engine-settings `charter` keys (config migration), panel and engine pages. Existing charter bookings and enquiries remain readable (history) — keep models read-only until 22-03 decides on archive tables.
2. Delete charter scenarios.

## Done when
Either HBUY-01 passes, or `grep -rni charter app/ routes/` returns only read-only legacy models listed in REPORT.
