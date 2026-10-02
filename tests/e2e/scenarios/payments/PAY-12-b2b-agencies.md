# PAY-12 · B2B: breached registration, partners, agency slideover
- **Tags:** sprint-5, payments
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
The 2-business-day agency SLA must show on the pending row. Approving it must move the partner to the table and keep the portal preview honest (net rates only).

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/commercial/b2b`. Date range **All dates**.
2. Read the four KPIs, **Registration requests — agent portal**, and **Travel-trade partners**.
3. On Andes Luxe Travel click `Approve`.
4. Open Meridian Voyages (row click / name). Read the slideover, including **Portal preview**.
5. Open Blue Latitude Travel. Read **Portal users**, **Portal access**, **Sales materials** and **Portal activity**. Do this before anyone has signed in on the portal in this reset.

## Expected
- [ ] E1 · Date-range line `2 partners · all dates`. KPIs: Approved agencies `2` · `portal access active`; Registrations to review `1` · `SLA: 2 business days (§10)`; Agency revenue `USD 49,875` · `2 approved agencies`; Commission accrued `USD 2,328` · `payable 30 days post-cruise`.
- [ ] E2 · Pending row: Andes Luxe Travel · `P. Ibáñez · p.ibanez@andesluxe.—` · `Signature · Chile` · Requested `11 Sep 2026` · SLA chip `SLA BREACH` (coral) · Commission asked `10%` · `Approve` / `Reject`.
- [ ] E3 · Partners: Blue Latitude Travel — S. Ferreira · Virtuoso · 10% · `30 days post-cruise · wire` · Bookings `1` · Revenue `USD 23,275` · Accrued `USD 2,328` · APPROVED. Meridian Voyages — T. Nakamura · ILTM · `15% >12% BLOCKED` · Bookings `0 + 1 held` · Revenue `USD 26,600` · `Blocked pending Director approval (FIN-005)` · APPROVED. Footnote about net rates / end guest / logged portal actions.
- [ ] E4 · After approve: toast `Agency approved. The portal invitation has been sent.` Mailpit, to the Andes Luxe contact, subject `Set your Iconic portal password`, with an `http://localhost:3002/accept?…` link. Registrations to review `0`. Approved agencies `3`. Andes Luxe is on the partners table. The SLA BREACH chip is gone.
- [ ] E5 · Meridian slideover shows the 15 % rate and the cap hold. Portal preview heading `Portal preview — what Meridian Voyages sees`. Notice `Preview of a portal that does not exist yet. Agents never see public prices. The client of record is always the end guest.` Sections `NET RATES (PUBLIC − 15%) · PUBLIC PRICES NEVER SHOWN`, `MY BOOKINGS`, `MY COMMISSIONS`, `SALES MATERIALS`.
- [ ] E6 · Open Blue Latitude Travel. **Portal users:** `Ada Agent · ada@portal.test` is `Active` and has no `last sign-in` line. **Portal access:** `Open`, with `Suspend`. **Sales materials:** `No sales materials yet.` **Portal activity:** `No portal activity yet.`

## Notes
Approve mutates AG-003 and sends a portal invitation — run this after photographing the breach chip. Blue Latitude's drawer is read before any portal sign-in, so Ada has no last-sign-in line.
