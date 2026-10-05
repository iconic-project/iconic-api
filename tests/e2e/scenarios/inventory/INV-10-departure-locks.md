# INV-10 · Departure locks with a block
- **Tags:** sprint-3, inventory
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
DEP-003 still has the demo block, and Sprint 4 also seeds two bookings on it. Date and yacht lock when cabins are sold; Delete names the active-claim counts. The history-only Delete sentence is unreachable here.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/booking-engine/departures`. Open DEP-003 (`14 Nov 2027` · ANAMARA).
2. Read **Embark date**, **Yacht**, the lock notice, and Delete.
3. Open `/rms/operations/blocks`. On `BLK-001` click `Release`. **Release** stays disabled while **Note** is empty. Type `E2E release BLK-001`. Click `Release`.
4. Return to Departures and open DEP-003 again.

## Expected
- [ ] E1 · Before release: date and yacht are disabled. Lock notice `Date and yacht are locked — 2 cabin(s) sold or held on this departure. Move guests with "Move to another departure" on each booking first.` ⚠ UNVERIFIED — `departures.lockNotice` + `dateAndYachtLockCount` (sold 2).
- [ ] E2 · Before release: Delete is disabled and reads `Delete (2 blocked, 2 sold)`. ⚠ UNVERIFIED — `DepartureLocks::activeDeleteMessage` wrapped as `Delete ({reason})`.
- [ ] E3 · After release: date and yacht stay locked (the two bookings remain). Delete is still disabled and reads `Delete (2 sold)`. ⚠ UNVERIFIED — same composers; history-only sentence is not reached.

## Notes
Keep DEP-003 (do not invent a block-only departure). Bookings lock date/yacht; the Sprint 3 “block does not lock date/yacht / history keeps Delete off” story no longer applies on this row.
