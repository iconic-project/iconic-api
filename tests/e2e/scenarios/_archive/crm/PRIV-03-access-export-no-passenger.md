# PRIV-03 · Access export has the relationship and no passenger record
- **Tags:** sprint-10, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Access is a zip the CRM does not open. Passenger fields stay out of it.

## Steps
1. Sign in as Carolina. Open `http://localhost:3001/crm/system/consent`.
2. **New request**: type **Access**, contact **A. Fontaine**, channel **Email**.
3. Open it. Enter how it was verified. Build the export. Download the zip.
4. Outside the panel, list the zip. Read `access.json` keys. Do not paste the file into the run report.

## Expected
- [ ] E1 · The drawer downloads `access-{id}.zip`. The panel does not render the file.
- [ ] E2 · The zip contains `access.json` covering the contact, consents, bookings, payments, documents and events.
- [ ] E3 · The JSON has no passport, date of birth, nationality, medical note, dietary note or accessibility note.

## Notes
If the build returns 500 because `storage/app/private/privacy` is not writable by the application user, fix the directory owner and retry. That is an environment fault, not an application change. A passenger field in the JSON is a **BUG**.
