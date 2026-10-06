# VIS-02 · Theme toggle everywhere
- **Tags:** visual
- **Priority:** P3
- **Users:** Carolina
- **Start:** reset

## Why
Dark is the default. Light must stay readable: no invisible text, no white boxes on dark.

## Steps
1. Sign in as Carolina. Visit each built page:
   - `/login` (sign out first), then sign back in
   - `/rms/reservations/calendar`
   - `/rms/admin/permissions`
   - `/rms/commercial/rates`
   - `/rms/booking-engine/settings`
   - `/rms/admin/business-rules`
   - `/crm/sales/pipeline`
2. On each page, toggle `◐ Light` / `◑ Dark` (theme control in the chrome).
3. Also open `http://localhost:3000/` and toggle if the engine exposes the same control.

## Expected
- [ ] E1 · Every listed page is readable in dark: body is the forest background, text is ivory, no white “unstyled” cards.
- [ ] E2 · Every listed page is readable in light: no forest-coloured text on forest, no missing borders.
- [ ] E3 · Inputs, tables, pills and the publish bar keep contrast in both themes.
