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
2. **Carolina** blocks every room of one published room type (Hotel Demo, Standard Double) for `2026-12-21` through `2026-12-23`. **Guest** opens `http://localhost:3000`, searches check-in `2026-12-21`, check-out `2026-12-24`, 2 adults. On the sold-out Standard Double card the stay is already filled. Name `E2E Waitlist`, email `e2e.off04.wait@iconic.test`. `Join the waitlist`.
3. **Carolina.** `/rms/reservations/booking-requests` — **Charter enquiries**. Then `/rms/operations/holds` — **Waitlist**, date range **All dates**.

## Expected
- [ ] E1 · Charter thank-you / SLA copy on the engine. RMS Charter enquiries: one row, contact `E2E Charter`, guests 8, status `NEW`, empty state is gone. ⚠ UNVERIFIED — i18n `charterEnquiries.*`; source `ENGINE` via db-check if the column is not on screen.
- [ ] E2 · Waitlist thanks: `You are on the waitlist.` RMS Waitlist shows `E2E Waitlist`, room type Standard Double, stay 21–24 Dec 2026. ⚠ UNVERIFIED — i18n `stayShop.waitlistThanks`.
- [ ] E3 · After reset the charter panel was `No charter enquiries.` Staff waitlist add (BKG-11) still works; this row is in addition.

## Cross-checks
- `bin/db-check.sh 'App\Models\CharterEnquiry::query()->latest("id")->first(["source","guests"])'` → `source` `ENGINE`, `guests` 8.
- `bin/db-check.sh 'App\Models\WaitlistEntry::query()->whereHas("contact", fn ($q) => $q->where("email","e2e.off04.wait@iconic.test"))->value("source")'` → `ENGINE`.

## Notes
Join the waitlist appears only when that room type is `SOLD_OUT` and `waitlist_enabled`. The stay on the card is the search. Guest context has no staff cookies. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
