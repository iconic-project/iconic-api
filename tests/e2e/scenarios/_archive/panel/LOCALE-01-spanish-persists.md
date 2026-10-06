# LOCALE-01 · Spanish panel chrome persists, then English returns
- **Tags:** sprint-15, panel
- **Priority:** P2
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
Staff can read the panel in Spanish. The choice is a cookie. Dates, money, API text, and two legal strings stay as they are. This is a sample, not a walk of every key.

## Steps
1. Sign in as Carolina. Confirm the topbar shows **EN** and **ES**, and **Sign out** is English.
2. Click **ES**.
3. Open `http://localhost:3001/crm/sales/inbox`, then `http://localhost:3001/crm/sales/b2b-partners`, then `http://localhost:3001/rms/reservations/calendar`, then `http://localhost:3001/crm/system/consent`.
4. Click **EN**. Reload once and read **Sign out**.
5. Click **ES** again. Reload.
6. Set the cookie `iconic_panel_locale` back to `en` and reload.

## Expected
- [ ] E1 · After **ES**, without a full navigation away from the shell, **Sign out** is `Cerrar sesión`. CRM nav shows `Bandeja — Correo · WhatsApp` and `Socios B2B`.
- [ ] E2 · Inbox title is `Bandeja de entrada`. B2B title is `Socios B2B`. RMS nav **Calendar** is `Calendario`. The calendar empty state, if the range is empty, is `No hay salidas en este intervalo de fechas.` Partner names and status codes (`PENDING`, `APPROVED`, `OPEN`) stay the stored code or name.
- [ ] E3 · A visible money figure still looks like `USD 46,550` (en-US). A visible date still looks like `23 Sep 2026`. `crmPrivacy.notice` is still the English LEG-002 sentence.
- [ ] E4 · After **EN**, **Sign out** is `Sign out` again. After step 5, a reload keeps Spanish (`iconic_panel_locale=es`). Step 6 returns the next page to English.

## Cross-checks
- Engine (`http://localhost:3000`) and portal (`http://localhost:3002`) stay English. Do not switch them. They have no **ES** control.
- `html lang` is `es` while Spanish is active and `en` after the cookie is set back.

## Notes
Default locale is `en`. Leaving `iconic_panel_locale=es` makes the next scenario Spanish, so step 6 is required. Do not translate a sampled string that is API copy (inbox subject, agency name). `crmPrivacy.pending` is also still English. This scenario does not walk every key.
