# WEB-07 · Pay later creates an ANK-R- request
- **Tags:** sprint-8, web
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Option 1 must land as a request the team can follow up — nationalities, fee choices and ENGINE consents included. A missing request is a lost booking.

## Steps
1. **Guest.** Same walkthrough as WEB-06 to `/book/details` (2 adults, 7 Nov 2027 ANAMARA, Suite 03). Promo optional.
2. Contact: First `E2E`, Last `Web07`, Email `e2e.web07@iconic.test`, phone `+1 555 0807`, preferred **Email**.
3. Guests: both nationalities **United States** (or `US`). Ecuador resident unchecked.
4. Fees: PNG **I will pay at SCY airport**; TCT **Arrange the TCT with the team later** (or the collect options — record which).
5. Declarations: tick **Privacy policy** and **Travel insurance declaration**. **Terms & Conditions** and **Cancellation policy** show `accepted with the deposit link` and are not required.
6. **Option 1 · Book now, pay later.** `Send booking request`.
7. Read `/book/confirmation`.
8. **Carolina.** Open `http://localhost:3001/rms/reservations/booking-requests`. Date range **All dates**. Open the new `ANK-R-` row (next after seed is **ANK-R-2026-0043**). Read SLA, party, guests, fee flags, consents.

## Expected
- [ ] E1 · Confirmation: `Request received` / `We have received your booking`. Reference `ANK-R-2026-0043`. Lead names `e2e.web07@iconic.test`. First confirmation card interpolates 24 hours. ⚠ UNVERIFIED — i18n `confirm.request*` + task 09 browser.
- [ ] E2 · Queue: three request rows (0041, 0042, 0043). 0043 contact `E2E Web07`, 7 Nov 2027 ANAMARA Suite 03, channel WEB / Hotel Booking Engine. SLA is live (not the seeded 19h/26h). ⚠ UNVERIFIED — `requests.notice` now mentions web bookings.
- [ ] E3 · Booking panel: two guests, nationality US (or United States), PNG/TCT choices as submitted. Consents PRIVACY + INSURANCE, source `ENGINE`, each has an IP. TERMS and CANCELLATION are not recorded yet.
- [ ] E4 · Charter enquiries panel on the same page stays empty (`No charter enquiries.`).

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first(["request_reference","status","png_collected","tct_collected","main_channel"])'` → `REQUESTED`, `WEB_DIRECT` / D2C.
- `bin/db-check.sh 'App\Models\Consent::query()->where("booking_id", App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->value("id"))->get(["document","source","ip"])'` → two rows, source `ENGINE`, `ip` non-null.

## Notes
Guest context has no staff cookies. Do not confirm or release 0043 here. Before any other click, click `Analytics off` (or set `localStorage['iconic-engine-analytics']` to `refused` and reload). Accepting analytics now also posts `POST /api/engine/events`. CRM-05 and CRM-06 own that behaviour.
