# CRM-06 · Without consent: no events, no identifier; the request still arrives
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Refusal must record nothing and store no session identifier. The booking request is independent of analytics.

## Steps
1. **Guest** (fresh context, no staff cookies). Open `http://localhost:3000/`. Before any other click, click **Analytics off**.
2. Open DevTools → Application (storage) and Network (filter `events`). Confirm `localStorage['iconic-engine-analytics']` is `refused` and `iconic-engine-session` is absent.
3. Same walk as CRM-05 (HENG-01 then HENG-03) to the request. Do not open a departure row.
4. Contact: First `E2E`, Last `Crm06`, Email `e2e.crm06@iconic.test`, phone `+1 650 253 0006`, preferred **Email**. Guests, fees and declarations as CRM-05.
5. **Option 1 · Book now, pay later.** `Send booking request`. Read `/book/confirmation`.
6. **Carolina.** Open `http://localhost:3001/rms/reservations/booking-requests`. Date range **All dates**. Open the new `ANK-R-` row (**ANK-R-2026-0043**).
7. Open `http://localhost:3001/crm/sales/contacts`. Search `e2e.crm06@iconic.test`. Open the drawer. Read the timeline. Open Activity and confirm no new anonymous stream for this browse. An anonymous name is plain text, not a button. A named contact on that page is a button that opens `/crm/sales/contacts?open={contact_id}`.

## Expected
- [ ] E1 · After **Analytics off**: `iconic-engine-analytics` = `refused`. No `iconic-engine-session` key. A current-session UTM touch may sit in `sessionStorage` — that is not the identifier.
- [ ] E2 · No request to `/api/engine/events` during browse or submit.
- [ ] E3 · Confirmation still shows `ANK-R-2026-0043` for `E2E Crm06`. Booking Requests lists the row. ⚠ UNVERIFIED — same next-reference as WEB-07.
- [ ] E4 · The new contact exists (SQL). Timeline has the booking **Requested** and ENGINE consents. It has no `page_view` / `view_departure` / `begin_checkout` items.
- [ ] E5 · Activity has no new anonymous row from this guest session. Anonymous cells are not links. A named contact opens the drawer by `contact_id`, not by searching the name.

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first(["request_reference","status"])'` → `REQUESTED`.
- `bin/db-check.sh 'App\Models\BehaviouralEvent::query()->where("contact_id", App\Models\Contact::query()->where("email","e2e.crm06@iconic.test")->value("id"))->count()'` → `0`.
- `bin/db-check.sh 'App\Models\Contact::query()->where("email","e2e.crm06@iconic.test")->value("engine_identified_at")'` → `null`.

## Notes
Guest context has no staff cookies. This is the README banner path (`Analytics off` / `refused`). Do not accept analytics later in the same context.
