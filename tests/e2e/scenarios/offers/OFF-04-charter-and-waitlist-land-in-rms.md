# OFF-04 · Charter enquiry and waitlist from the engine land in the RMS
- **Tags:** sprint-8, offers
- **Priority:** P2
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
K10: waitlist and private-charter enquiries must arrive in the RMS with source ENGINE.

## Steps
1. **Guest.** Open `http://localhost:3000/charter`. Full name `E2E Charter`, Email `e2e.off04.charter@iconic.test`, guests `8`, When **Preferred dates** from `2027-11-07` to `2027-11-21` (or pick a departure). Notes `E2E charter enquiry`. `Request charter proposal`.
2. Still as guest: force a waitlist surface. Either continue from a leftover FULL · WAITLIST / LIMITED row (if you just ran WEB-03 without reset — this file **starts reset**, so do not rely on that) **or** have Carolina block all nine cabins on **7 Nov 2027 ANATIVA** (same as WEB-03 steps 2+4), then Guest opens Northern Passage → **Waitlist** on that row. Category **Suite**, name `E2E Waitlist`, email `e2e.off04.wait@iconic.test`, `Join the waitlist`.
3. **Carolina.** `/rms/reservations/booking-requests` — **Charter enquiries**. Then `/rms/operations/holds` — **Waitlist**, date range **All dates**.

## Expected
- [ ] E1 · Charter thank-you / SLA copy on the engine. RMS Charter enquiries: one row, contact `E2E Charter`, guests 8, status `NEW`, empty state is gone. ⚠ UNVERIFIED — i18n `charterEnquiries.*`; source `ENGINE` via db-check if the column is not on screen.
- [ ] E2 · Waitlist thanks: `Thank you — you are on the waitlist.` RMS Waitlist: `E2E Waitlist` · Suite · 7 Nov 2027 ANATIVA, plus the two seeded festive rows (Anna / K. Osei). ⚠ UNVERIFIED — i18n `waitlist.thanks`.
- [ ] E3 · After reset the charter panel was `No charter enquiries.` Staff waitlist add (BKG-11) still works; this row is in addition.

## Cross-checks
- `bin/db-check.sh 'App\Models\CharterEnquiry::query()->latest("id")->first(["source","guests"])'` → `source` `ENGINE`, `guests` 8.
- `bin/db-check.sh 'App\Models\WaitlistEntry::query()->whereHas("contact", fn ($q) => $q->where("email","e2e.off04.wait@iconic.test"))->value("source")'` → `ENGINE`.

## Notes
Waitlist overlay only where the feed says waitlist is on (FULL · WAITLIST / LIMITED / party-fit). Blocking ANATIVA 7 Nov is the reset-safe way to get that row. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
