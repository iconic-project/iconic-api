# GST-06 · Record a missing consent; row is append-only
- **Tags:** sprint-6, guests
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
Staff can record a consent obtained off-line. The table is append-only: nothing edits or deletes a row (I6). 0007 is the only CONFIRMED seed missing marketing.

## Steps
1. Sign in as Carolina. Open ANK-2026-0007. **Guests** tab. Scroll to **Consent records (§6.4)**.
2. Read the five document rows.
3. On **Marketing (optional)** click `Record…`. Modal title `Record "Marketing (optional)" consent`. Label `How was it obtained?`. Type `E2E phone 0007`. Submit.
4. Read the marketing row. Confirm there is no edit or delete control on any consent row.

## Expected
- [ ] E1 · Terms & Conditions, Cancellation policy, Privacy policy and Travel insurance declaration are present, source `Payment link`, accepted `2 Jul 2026, 14:05` (Galápagos). Versions from published `legal.consent_versions` (`v2026.1` / `OPS-005 v1`). ⚠ UNVERIFIED — DemoConsentsSeeder / task 07 browser.
- [ ] E2 · Marketing accepted cell is `Not given` (optional, not `Missing`). `Record…` is the only action.
- [ ] E3 · After record: toast `Consent recorded`. Marketing source cell `Staff — E2E phone 0007`. No edit / no delete on any row.

## Notes
Marketing is never required, so missing-consents does not fire on 0007. The four required docs are already seeded from the payment link.
