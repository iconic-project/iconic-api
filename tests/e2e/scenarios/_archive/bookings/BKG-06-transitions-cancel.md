# BKG-06 · Legal transitions and cancellation

Retired in sprint 19. Hotel stays are HBKG-01 through HBKG-10. Do not walk these yacht steps.
- **Tags:** sprint-4, bookings
- **Priority:** retired
- **Users:** Carolina
- **Start:** reset

## Why
A CONFIRMED booking may only move to FULLY_PAID or CANCELLED. Cancel needs a reason, frees the cabin, writes the reason into History, and — because 0003 already has a settled deposit — queues a refund (Sprint 5).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/reservations/bookings`. Open ANK-2026-0003 (Harrison & Whitfield). Overview tab.
2. Read **Status transitions (only legal moves shown)** and Delete. Read Paid / Balance due / Deposit.
3. Click `→ CANCELLED`. In the modal titled `Status CONFIRMED → CANCELLED`, leave Reason empty — `Record` stays disabled. Type `E2E cancel 0003`. `Record`.
4. Open History. Open Calendar Year 2027, ANAMARA Suite 01 on `7 Nov 2027`. Open `/rms/operations/refunds`.

## Expected
- [ ] E1 · Legal buttons only: `→ FULLY PAID` and `→ CANCELLED`. `Delete (admin only)` is enabled (Carolina). No `→ IN HOUSE` / `→ CHECKED OUT` / `→ RELEASED`. Paid `USD 2,660`. Balance due `USD 23,940 · due 10 Jul 2027`. Deposit `Deposit 10% · USD 2,660 ✓`.
- [ ] E2 · Reason modal title `Status CONFIRMED → CANCELLED`. Label `Reason (required)`. ⚠ UNVERIFIED — `reasonModalTitle()` / i18n; task 07 browser.
- [ ] E3 · Toast `Status updated` (or the panel closes/refreshes to CANCELLED). ⚠ UNVERIFIED — i18n `bookings.transitionedToast`.
- [ ] E4 · History includes the reason `E2E cancel 0003` and a status-changed sentence CONFIRMED → CANCELLED (seeded create line stays `Reservation created in RMS — Suite 01 · 2 AD · seeded`). A refund-requested line with penalty `USD 1,330` and refund `USD 1,330` (5 % of 26,600; paid 2,660). ⚠ UNVERIFIED — History rendering of `booking.status_changed` / `refund.requested`.
- [ ] E5 · Calendar Year 2027: ANAMARA Suite 01 on 7 Nov is `·` (Available).
- [ ] E6 · Refund Approvals is no longer empty. ANK-2026-0003 · policy band `≥120 days → 5%` · Penalty `USD 1,330` · Refund due `USD 1,330 of USD 2,660 paid`. (PAY-10 walks approve + execute.)

## Notes
Do not confirm cancel against a group row. ANK-2026-0003 is a single-cabin CONFIRMED booking with a settled deposit. Cancelling a zero-paid booking would show `Nothing was paid, so nothing is owed.`
