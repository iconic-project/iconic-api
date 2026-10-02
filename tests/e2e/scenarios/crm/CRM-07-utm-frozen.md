# CRM-07 · UTM landing is written once and cannot be changed
- **Tags:** sprint-9, crm
- **Priority:** P2
- **Users:** Guest + Carolina
- **Start:** reset
- **Needs:** two browser contexts

## Why
Attribution is written on insert. If a later update can change `utm_first` / `utm_last`, the first-touch contract is broken.

## Steps
1. **Guest** (fresh context). Open `http://localhost:3000/?utm_source=e2e&utm_campaign=sprint9`. Before any other click, click **Analytics on**.
2. Same pay-later walkthrough as CRM-05 (2 adults, 7 Nov 2027 ANAMARA, Suite 03). Contact: First `E2E`, Last `Crm07`, Email `e2e.crm07@iconic.test`, phone `+1 650 253 0007`, preferred **Email**.
3. `Send booking request`. Confirmation reference **ANK-R-2026-0043**.
4. **Carolina.** Open `http://localhost:3001/crm/sales/contacts`. Search `e2e.crm07@iconic.test`. Open the drawer. Read **First touch** and **Last touch**.
5. Open `/rms/reservations/bookings?open=ANK-R-2026-0043` (or Booking Requests → the row). Confirm the RMS panel has no UTM fields to edit.
6. Read `utm_first` / `utm_last` on the booking (the booking JSON, not a panel label). Then run the write probe in Cross-checks.

## Expected
- [ ] E1 · Drawer First touch and Last touch render `e2e · sprint9` (`formatAttribution` joins source · campaign; landing path may append ` · /`). ⚠ UNVERIFIED — i18n + `contactHelpers.formatAttribution`.
- [ ] E2 · The RMS booking panel does not paint `utm_first` / `utm_last` and has no control that edits them (`BookingPanel.vue` has none).
- [ ] E3 · `GET /api/rms/bookings/{id}` (or the open booking payload) has `utm_first.source` `e2e` and `utm_first.campaign` `sprint9`, same on `utm_last`. ⚠ UNVERIFIED — `BookingResource`.
- [ ] E4 · The write probe returns `booking attribution columns are immutable` (SQLSTATE 45000). The stored JSON is unchanged.

## Cross-checks
- `bin/db-check.sh 'App\Models\Booking::query()->where("request_reference","ANK-R-2026-0043")->first(["utm_first","utm_last"])'` → both touches include `source` `e2e` and `campaign` `sprint9`.
- `bin/db-check.sh 'App\Models\Contact::query()->where("email","e2e.crm07@iconic.test")->first(["first_touch","last_touch"])'` → same source / campaign.
- `db-check.sh` is read-only (it refuses `update`). Run this write probe; it must fail:

```bash
docker compose exec app sh -c "php artisan tinker --execute=\"try { \Illuminate\Support\Facades\DB::table('bookings')->where('request_reference','ANK-R-2026-0043')->update(['utm_first' => json_encode(['source' => 'tamper'])]); echo 'UNEXPECTED_OK'; } catch (Throwable \\\$e) { echo \\\$e->getMessage(); }\""
```

Expected fragment: `booking attribution columns are immutable`.

## Notes
Do not add a panel label that is not in `BookingPanel.vue`. Guest context has no staff cookies. Accept analytics so the engine sends `attribution` + `session_id` on submit.
