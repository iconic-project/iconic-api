# CRM-05 · With consent: browse and submit stitches anonymous events
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
A consented visitor’s itinerary and checkout events must land on the new contact once they identify themselves. If the stitch fails, the timeline is empty and the session stays anonymous.

## Steps
1. **Guest** (fresh context, no staff cookies). Open `http://localhost:3000/`. Before any other click, click **Analytics on**. Confirm `localStorage['iconic-engine-analytics']` is `accepted` and `localStorage['iconic-engine-session']` exists.
2. Open DevTools → Network. Filter `events`. Walk a stay search (HENG-01) through pay later (HENG-03) so `page_view` queues. `view_departure` still counts when the client sends that name.
3. Wait until `POST /api/engine/events` appears (flush every few seconds, or hide the tab). Record `session_id` from the request body.
4. Contact: First `E2E`, Last `Crm05`, Email `e2e.crm05@iconic.test`, phone `+1 650 253 0005`, preferred **Email**. Guests: both nationalities **United States**. Fees: PNG **I will pay at SCY airport**; TCT **Arrange the TCT with the team later**. Declarations: **Privacy policy** and **Travel insurance declaration**.
5. **Option 1 · Book now, pay later.** `Send booking request`. Read `/book/confirmation` — next request after seed is **ANK-R-2026-0043**.
6. **Carolina.** Open `http://localhost:3001/crm/sales/contacts`. Search `e2e.crm05@iconic.test`. Open the row. Read lifecycle and the timeline.
7. Open `http://localhost:3001/crm/engine/activity`. Find events for this contact. Confirm they are not left as **anonymous**. Click the contact. The contacts page opens with `?open=` set to that contact’s id, and the drawer is that person — not a name search.

## Expected
- [ ] E1 · After **Analytics on**: `iconic-engine-analytics` = `accepted`; `iconic-engine-session` is a JSON object with a random `id`. ⚠ UNVERIFIED — `engineSession.ts`.
- [ ] E2 · At least one `POST http://localhost:8000/api/engine/events` (or the engine API base) with that `session_id` and a `page_view` and/or `view_departure`. No email, name or phone in `params`.
- [ ] E3 · Confirmation shows `ANK-R-2026-0043` for `E2E Crm05`. ⚠ UNVERIFIED — same next-reference as WEB-07.
- [ ] E4 · Carolina: contact lifecycle **SQL** (REQUESTED outranks MQL). Timeline includes stitched behavioural titles (`page_view` / `view_departure` / `begin_checkout` as the API sends them) plus the booking **Requested**. ⚠ UNVERIFIED — `ContactTimeline` behavioural copy + stitch on checkout submit.
- [ ] E5 · Activity stream names this contact (not `anonymous`) for those events. Side badges include **CRM**. The row’s `contact_id` is the new contact. Clicking it opens `/crm/sales/contacts?open={id}` on that drawer.

## Cross-checks
- `bin/db-check.sh 'App\Models\Contact::query()->withDerived()->where("email","e2e.crm05@iconic.test")->first()'` → `lifecycle` `SQL`, `engine_identified_at` not null.
- `bin/db-check.sh 'App\Models\BehaviouralEvent::query()->where("contact_id", App\Models\Contact::query()->where("email","e2e.crm05@iconic.test")->value("id"))->pluck("name")'` → includes `page_view` and `identity.stitched` (and `view_departure` if the departure row was opened). ⚠ UNVERIFIED — stitch back-fill.

## Notes
Guest context has no staff cookies. Click **Analytics on** — do not leave the banner up. CRM-06 is the refusal path. Do not confirm 0043.
