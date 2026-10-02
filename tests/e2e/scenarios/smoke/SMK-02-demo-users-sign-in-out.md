# SMK-02 · Every demo user can sign in and out
- **Tags:** smoke, sprint-1, auth
- **Priority:** P1
- **Users:** Carolina · Mateo · Lucía · CFO
- **Start:** reset

## Why
Wrong landing, header, or leftover session breaks every role-specific scenario.

## Steps
For each user below: sign out (or use a fresh private context) before the next.

1. Open `http://localhost:3001/login`. Sign in with `carolina@iconic.test` / `password`. Click `Sign in`.
2. Open the account menu (who-menu). Click `Sign out`.
3. Sign in as `mateo@iconic.test` / `password`. Sign out.
4. Sign in as `lucia@iconic.test` / `password`. Sign out.
5. Sign in as `cfo@iconic.test` / `password`. Sign out.

## Expected
- [ ] E1 · Carolina lands on `/rms/reservations/calendar`. Header who-menu shows `CAROLINA M. — ADMIN`. Sidebar includes **Permissions** and **Business Rules**. Section switch **RMS** / **CRM** is visible.
- [ ] E2 · After Carolina signs out, the URL is `/login` and the `Sign in` form is visible.
- [ ] E3 · Mateo lands on `/rms/reservations/calendar`. Header shows `MATEO R. — MANAGER`. Section switch is visible. **Permissions** and **Business Rules** are not in the sidebar.
- [ ] E4 · Lucía lands on `/rms/reservations/calendar`. Header shows `LUCÍA B. — SALES EXEC`. Same sidebar gating as Mateo.
- [ ] E5 · CFO lands on `/rms/reservations/calendar`. Header shows `CFO (EXTERNAL) — EXTERNAL FINANCE`. No section switch. No **＋ New Reservation**.
- [ ] E6 · After the CFO signs out, the URL is `/login`.

## Notes
Header format is `{name} — {role}` uppercased (em dash). See `fixtures/accounts.md`.
