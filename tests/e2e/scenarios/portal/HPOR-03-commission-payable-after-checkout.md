# HPOR-03 · Commission payable date is after check-out
- **Tags:** sprint-20, portal
- **Priority:** P2
- **Batch:** B26
- **Users:** Ada Agent
- **Start:** continues from HPOR-02

## Why
The commission list shows the stay and a payable date counted from check-out, not from a departure.

## Steps
1. Continue from HPOR-02. Do not reset.
2. As Ada, open `http://localhost:3002/commissions`. Find the reference from HPOR-02.

## Expected
- [ ] E1 · The row shows the stay `21 Dec 2026 – 25 Dec 2026` (check-in through check-out).
- [ ] E2 · Payable date is check-out plus the published `commission.payable_days_after_check_out`. The published initial value is 30, so the date is 24 Jan 2027. ⚠ UNVERIFIED — read the current business-rules document if a later publish changed the days.
- [ ] E3 · The row does not show a departure date column.

## Notes
Walk HPOR-02 first. A reset drops the request. The payable date comes from `Accrual::payableDate`.
