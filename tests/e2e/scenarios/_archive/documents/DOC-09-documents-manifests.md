# DOC-09 · Documents: filter and open the booking
- **Tags:** sprint-7, documents
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Client documents across bookings must come from `GET /documents` and the same plan as the booking tab. Hand-written kind/status chips would drift from the API.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/rms/operations/documents`. Date range **All dates**.
2. Read the notice, the **Client documents** table, kind chips and status chips. Do **not** type kind or status labels from memory — they come from `meta.filters`.
3. Click status chip `SENT`. Then click kind chip `Booking Confirmation & Invoice` (or the API’s label for `INVOICE`). Confirm every visible row matches both filters.
4. Click a row for ANK-2026-0003 (Harrison & Whitfield). Confirm the booking panel opens on **Documents**.
5. Click `All` on both chip stacks. Confirm the page has no generate-manifest control.

## Expected
- [ ] E1 · Title **Client documents**. Notice mentions mockup v6 and does not mention DPNG or a captain's manifest. Columns: Booking · Client · Document · Trigger · Date · Status. Kind chips and status chips include `All` plus every value from the API (`SENT`, `FAILED`, `BLOCKED`, `SCHEDULED`, `WAITING`, `NOT NEEDED`, `NOT CONTRACTED`, `DUE` and the plan kinds). ⚠ UNVERIFIED — task 07 browser on a local seed, not a cloud reset.
- [ ] E2 · After SENT + invoice filters: every row is that kind and `SENT`. Seeded CONFIRMED / FULLY_PAID bookings appear (0003 Harrison & Whitfield among them).
- [ ] E3 · Row click opens the booking panel with tab **Documents** (not Overview). 0003 shows the same plan as DOC-01.
- [ ] E4 · No departure-manifests section and no generate control. Existing manifest files stay on the booking history tab, read only. No KPI counts (the API does not send per-status totals).

## Notes
Nav label `Documents`. Permission `panel.rms`. Search `q` is optional in this script. Lucía / own-records is not this scenario.
