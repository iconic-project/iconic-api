# BKG-11 · Waitlist
- **Tags:** sprint-4, bookings
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
The waitlist is its own table (G7), FIFO per departure + category. Notify is manual; remove updates positions.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/operations/holds`. Date range **All dates**. Read **Waitlist**.
2. `＋ Add to waitlist`. Departure `19 Dec 2027` · ANAMARA (festive / FULL). Cabin type **Suite**. Contact `E2E Waitlist`, email `e2e.bkg11@iconic.test`. `Add to waitlist`.
3. On Anna Whitfield click `Mark notified`. Channel **EMAIL**. Submit `Mark notified`.
4. On the new Suite row click `Remove`. Confirm `Remove from waitlist`.

## Expected
- [ ] E1 · Seed rows: Anna Whitfield · Suite · position **1**; K. Osei · Owner's Suite · position **1** (different categories). Departure 19 Dec festive. No ENGINE-sourced row until OFF-04. ⚠ UNVERIFIED — on-screen position column and Owner label `Owner's Suite`. RMS waitlist resource does not serialise `source`; ENGINE is a `db-check` in OFF-04.
- [ ] E2 · After add: toast `Added to the waitlist`. Two Suite rows on that departure — Anna still **1**, E2E Waitlist **2**. K. Osei still Owner **1**. ⚠ UNVERIFIED — i18n `holds.addedToast`; FIFO by `created_at`.
- [ ] E3 · After notify: `Notified {date} via EMAIL by Carolina M.` `Mark notified` hidden on Anna; `Remove` remains. Date is Galápagos. ⚠ UNVERIFIED — i18n `holds.notifiedLine`; task 09 used `20 Sep 2026`.
- [ ] E4 · After remove: toast `Removed from the waitlist`. E2E Waitlist gone. Anna Suite position **1**. ⚠ UNVERIFIED — i18n `holds.removedToast`.

## Notes
Button is **Mark notified** (G7), not the prototype’s “Notify now”. 19 Dec ANAMARA is the seeded charter (FULL). Staff add still works after Sprint 8; engine waitlist entries are OFF-04.
