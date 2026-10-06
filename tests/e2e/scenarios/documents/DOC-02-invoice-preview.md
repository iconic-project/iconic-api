# DOC-02 · Preview the invoice: subtotals match Overview
- **Tags:** sprint-7, documents
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Documents never compute money (J10). If the preview’s three subtotals and invoice total disagree with Overview, the stored PDF is a different booking.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Overview**: read Cruise, Galápagos fees collected, Extras, Charges total.
2. **Documents** tab. On **Booking Confirmation & Invoice** click `Preview`.
3. Read the totals block in the iframe (Stay charges / Taxes and fees collected / Other services / INVOICE TOTAL). Note `Print / save PDF` and `Download PDF`.
4. Click `Print / save PDF`. **Do not** confirm or dismiss the browser print dialog as an automated assertion — record that it opened (or that the click was not driven). Close Preview.

## Expected
- [ ] E1 · Overview: Cruise `USD 26,600`. Galápagos fees collected `USD 0`. Extras `USD 0`. Charges total `USD 26,600`. Paid `USD 2,660`. Balance due `USD 23,940 · due 10 Jul 2027`.
- [ ] E2 · Preview iframe shows issuer **PONTOS LLC**, property **ANAMARA**, and the three subtotals `26,600.00` / `0.00` / `0.00` with `INVOICE TOTAL` `USD 26,600.00`. Cents are on the document only (J10 / Money::formatDocument). ⚠ UNVERIFIED — task 02 snapshot + 0003 seed, not a cloud reset.
- [ ] E3 · `Print / save PDF` is present and enabled. `Download PDF` is present (this version is issued). The print dialog itself is **not** automated — the run report records that.
- [ ] E4 · Header on the document includes the booking reference `ANK-2026-0003` and an invoice number `INV-…` plus `v1`. Copy the number from the screen into the run report.
- [ ] E5 · The same preview includes **Your stay** (check-in and check-out with times), **Taxes and fees**, and a **Cancellation** row. The stay is not a departure date.

## Notes
0003 has no seeded extras or collected fees (`DemoExtrasSeeder` attaches FLT to 0011, HPRE to 0007, PNG to 0009). Bank block prints `[TBD]` (LEG-004).
