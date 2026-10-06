# PAY-07 · Apply the unmatched gateway charge
- **Tags:** sprint-5, payments
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
A Stripe charge with no RMS match must be appliable. Applying it writes a ledger row and drops the discrepancy count.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/payments`. Date range **All dates**. Read reconciliation.
2. On `CH_UNMATCHED` click `Apply to booking`. Modal title `Apply gateway charge to a booking`. Stripe line includes `ch_unmatched`. Booking reference `ANK-2026-0003` (or search Harrison). Type stays a recordable kind (Balance). `Apply to booking`.
3. Read reconciliation counts and 0003's Payments tab.

## Expected
- [ ] E1 · Before apply: Gateway 2 · Matched 1 · Discrepancies 1. Row `CH_UNMATCHED` · 19 Sep 2026 · `USD 2,660` · `Unmatched gateway charge`.
- [ ] E2 · Toast `Gateway charge applied`. Discrepancies becomes 0 (tone no longer coral). The unmatched row is gone. Matched is 2.
- [ ] E3 · ANK-2026-0003 ledger has a new SETTLED row for `USD 2,660` whose gateway id is `ch_unmatched` / `pi_unmatched`. Paid on Overview is `USD 5,320`. Status stays CONFIRMED (balance remaining).

## Notes
Fixture file `database/fixtures/stripe-charges.json` — unmatched `created_days_ago: 1`. Applying to 0003 is a test apply, not a real guest payment. Do not apply to 0014 (that booking's deposit is already pledged as a wire).
