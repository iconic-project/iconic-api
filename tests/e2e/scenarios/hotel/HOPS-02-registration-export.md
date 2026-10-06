# HOPS-02 · Registration export for a night, sensitive columns only for permitted users
- **Tags:** sprint-21, hotel, guests
- **Priority:** P1
- **Batch:** B27
- **Users:** Carolina, then Lucía
- **Start:** reset
- **Needs:** two browser contexts

## Why
The registration file is the guest list for one night. Nationality, date of birth, and document number are sensitive. A user without `guests.view_sensitive` does not receive those columns.

## Steps
1. **Carolina.** Open HTL-027. **Guests** tab. `＋ Add guest`. First name `E2E`, surname `Register`, nationality `DE`, date of birth `1990-04-02`, passport number `E2EREG270`. Save.
2. Open `http://localhost:3001/rms/reservations/front-desk`. Set the date to `2026-10-12`. Format **CSV**. Click **Export registration**. Open the file.
3. Sign out. **Lucía** (`lucia@iconic.test` / `password`) opens the same front desk date and exports CSV again.

## Expected
- [ ] E1 · Carolina's header includes `full name`, `nationality`, `date of birth`, `document number`, `check-in`, and `check-out`.
- [ ] E2 · Carolina's file has a row for `E2E Register` with nationality `DE`, date of birth `1990-04-02`, document number `E2EREG270`, check-in `2026-10-12`.
- [ ] E3 · Lucía's header has `full name`, `check-in`, and `check-out`. It has no `nationality`, `date of birth`, or `document number` column. The row does not contain `E2EREG270` or `1990-04-02`.

## Notes
HTL-027 occupies the night of 12 Oct 2026. Lucía has front desk and does not have `guests.view_sensitive`. Do not paste the passport into the run report beyond confirming E3's absence. Dummy number only.
