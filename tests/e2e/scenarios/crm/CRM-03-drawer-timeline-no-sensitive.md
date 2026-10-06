# CRM-03 · Contact drawer: RMS bookings, timeline, no sensitive fields
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The drawer is the CRM profile. Bookings open in the RMS. The timeline must list booking, payment, document and consent items. Passport, date of birth, nationality and notes must never appear.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/contacts`.
2. Search `Harrison`. Open **Harrison & Whitfield**.
3. Read the header (type · lifecycle · contact id), country · language, channels, LTV, first / last touch, NPS, consent, bookings and timeline.
4. Click booking **ANK-2026-0003**. Confirm the RMS booking panel opens.
5. Return to the drawer. Read **Not held here**. Search the drawer text (and the contact JSON) for passport, date of birth, nationality and note.
6. Read the **Journeys** section.

## Expected
- [ ] E1 · Header includes type **Direct Passenger** · lifecycle **BOOKED** · `contact_id {id}`. Country · language plus pill **ALL SENDS IN ENGLISH**. LTV **FROM RMS LEDGER** · **USD 26,600** · **HIGH**. First / last touch `—`. Historical NPS `—`. ⚠ UNVERIFIED — i18n + seed charges; task 06 walked Fontaine, not this row.
- [ ] E2 · Consent: transactional **ALWAYS ON**. Marketing is **OPTED IN** or **NOT OPTED IN** from `GET /api/crm/contacts/{id}/consents`, not a typed chip. There is no line `Consent register arrives in Sprint 10`. **History** lists the register rows. Lifecycle in the header is still **BOOKED** — the register does not change it.
- [ ] E3 · Bookings heading `Bookings` · `RMS — READ ONLY`. Row **ANK-2026-0003** · CONFIRMED · 7 Nov 2027 · **USD 26,600**. The link is `/rms/reservations/bookings?open=ANK-2026-0003`.
- [ ] E4 · Timeline lists at least: title **Created** (kind booking), title **Payment**, title **Delivery**, title **Consent**. ⚠ UNVERIFIED — `ContactTimeline` copy + seeded ledger / documents / consents on 0003, not a reset walk.
- [ ] E5 · **Not held here**: `Passport, date of birth, nationality, and medical, dietary or mobility notes stay in the RMS.`
- [ ] E6 · The drawer shows none of: passport number, date of birth, nationality, medical / dietary / accessibility note. `GET /api/crm/contacts/{id}` (the open request) has none of those keys.
- [ ] E7 · **Journeys** is on the drawer. Fresh seed shows `No enrolments.`
- [ ] E8 · **Stays** reads `{stays} stays · {nights} nights` with stays at least 1, and **Next check-in** for ANK-2026-0003. It does not call that date a departure. Detail is HCRM-01.

## Cross-checks
- `bin/db-check.sh 'App\Models\Contact::query()->where("name","Harrison & Whitfield")->withDerived()->first()'` → `lifetime_value` 26600, `segment` `HIGH`, `lifecycle` `BOOKED`. ⚠ UNVERIFIED — sold Suite · 2 adults.

## Notes
0003 has a settled deposit, sent documents and seeded consents — that is why this row, not a waitlist contact. Do not write guest personal data into the report.
