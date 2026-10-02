# Demo accounts

Seeded only when `APP_ENV` is `local` or `testing` ([`DemoUsersSeeder`](../../../database/seeders/DemoUsersSeeder.php)). Password for every user: `password`.

After sign-in the header who-menu shows `{NAME} — {ROLE}` **uppercased** (em dash). Landing with no `redirect` is `/rms/reservations/calendar` for anyone with `panel.rms`.

| Name on screen | Email | Role name | Header |
|---|---|---|---|
| Carolina M. | carolina@iconic.test | Admin | `CAROLINA M. — ADMIN` |
| Mateo R. | mateo@iconic.test | Manager | `MATEO R. — MANAGER` |
| Lucía B. | lucia@iconic.test | Sales Exec | `LUCÍA B. — SALES EXEC` |
| CFO (external) | cfo@iconic.test | External finance | `CFO (EXTERNAL) — EXTERNAL FINANCE` |

## Portal

The agent site is not the staff panel. Sign in at `http://localhost:3002/login`. This account is an agency user on Blue Latitude Travel (AG-001). It has no staff role and no staff permission.

| Name | Email | Agency | Password |
|---|---|---|---|
| Ada Agent | ada@portal.test | Blue Latitude Travel | `password` |

Meridian Voyages (AG-002) has no seeded portal login. PREQ-02 creates one with `tests/e2e/bin/setup.sh portal-user AG-002`. That command prints `e2e-portal-ag-002@portal.test` and the password `password`. `portal-invite` uses `e2e-invite-<reference>@portal.test` and leaves the invitation outstanding.

## What each should and should not see

### Carolina (Admin)
- RMS and CRM. Section switch **RMS** / **CRM** is visible.
- Sidebar includes **Permissions** and **Business Rules**.
- **＋ New Reservation** is visible on RMS.
- Rates, Engine Settings and Business Rules are editable (publish with an approval reference where required).

### Mateo (Manager)
- RMS and CRM. Section switch visible. **＋ New Reservation** visible.
- **No** sidebar items for **Business Rules** or **Permissions**. Direct URLs redirect to Calendar with toast `You don't have permission to do that.`
- Engine Settings: **copy** panels editable (`Booking notes & messages`, `Confirmation page — what happens next`, charter copy). **Rules** locked: guests, calendar, fee amounts, charter **Response SLA (hours)**.
- Rates: read-only (no `rates.manage`).
- Inventory write: **Itineraries**, **Departures** and **Internal Blocks** (`itineraries.manage`, `departures.manage`, `blocks.manage`).

### Lucía (Sales Exec)
- RMS and CRM. Section switch visible. **＋ New Reservation** visible.
- **No** Business Rules or Permissions in the sidebar. Direct URLs → Calendar + the same forbidden toast.
- Rates: **VIEW ONLY — ADMIN / DIRECTOR EDITS RATES**. Inputs disabled, no helper row, no approval field.
- Engine Settings: **VIEW ONLY — SALES EXEC**. Everything disabled.
- Inventory read: Itineraries, Departures and Internal Blocks show no write controls (no `＋ New itinerary` / `Generate season…` / `＋ New departure` / `＋ New block`; status selects disabled). Calendar and Yacht Layout are readable.

### CFO (external)
- **RMS only.** No section switch. No **＋ New Reservation**.
- `/crm/...` redirects to `/rms/reservations/calendar`.
- No Business Rules or Permissions.
