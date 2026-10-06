# DLV-01 · The delivery log matches the booking’s Documents tab
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
The CRM shows what the RMS sent. It does not render or resend.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/sales/documents`.
2. Read the KPI row and the engagement note. Pick the first **ANK-2026-0003** row (invoice or summary).
3. Read booking, client, document, RMS-rendered, channel, status and time.
4. **Open in the RMS**.
5. On the booking’s Documents tab, find the same document. Look for a Resend control on the CRM log (not on the RMS tab).

## Expected
- [ ] E1 · Notice: `Documents are issued once by the RMS. The CRM shows what was sent, when, and what happened. It never renders or resends a document.`
- [ ] E2 · Engagement note: `Opens and downloads are not tracked (LEG-002)`.
- [ ] E3 · The CRM row and the RMS Documents row name the same booking, document and status. The link is `/rms/operations/documents?booking={id}` and the panel opens on **Documents**.
- [ ] E4 · The CRM log has no Resend button and no recipient address. The client is a name.

## Notes
Seeded deliveries from `DemoDocumentsSeeder` are **SENT**. A FAILED or BLOCKED row is not part of that seeder. Do not require one after reset. Task 10 saw a failed and a blocked row on a database that had already sent mail; that is not the reset contract. ⚠ UNVERIFIED — KPI numbers, read them off the reset screen.
