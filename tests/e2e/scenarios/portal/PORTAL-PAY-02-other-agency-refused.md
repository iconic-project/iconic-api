# PORTAL-PAY-02 · Another agency's booking cannot be paid

Retired in sprint 20. Hotel seed has no `ANK-2026-0021`. The other-agency refusal stays in Pest (`PortalCrossAgencyScopeTest`). Do not walk these steps.
- **Tags:** sprint-15, portal
- **Priority:** retired
- **Batch:** B20
- **Users:** Ada Agent
- **Start:** reset

## Why
Portal lists are already this agency's rows, so the pay button is not offered for someone else's booking. The API still refuses the post.

## Steps
1. Sign in as Ada at `http://localhost:3002/login`.
2. Open `http://localhost:3002/bookings` and `http://localhost:3002/requests`. Search the page for `ANK-2026-0021`.
3. With Ada's portal session, `POST /api/portal/bookings/{id}/payment-link` with body `{ "kind": "DEPOSIT" }`, where `{id}` is the booking id of `ANK-2026-0021`.
4. From `iconic-api` run `tests/e2e/bin/setup.sh portal-pay ANK-2026-0021 DEPOSIT`.

## Expected
- [ ] E1 · Neither list shows `ANK-2026-0021`. No **Pay deposit** and no **Pay balance** control exists for that booking.
- [ ] E2 · The post returns 403. Message: `This booking is not available to your agency.`
- [ ] E3 · `portal-pay` dies. It prints that same error and does not print a `url`.

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("reference","ANK-2026-0021")->first()->paymentLinks()->count()'` → `0`.
- `bin/db-check.sh 'App\Models\Booking::query()->where("reference","ANK-2026-0021")->first()->agency->reference'` → `AG-002`.

## Notes
`ANK-2026-0021` is Meridian Voyages (AG-002), seeded `ON_HOLD_AGENCY`. Ada is Blue Latitude Travel (AG-001). The helper calls `CreatePortalPaymentLink` directly and must not insert a link when the agency does not own the booking.
