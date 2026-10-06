# GST-04 · Minor today: guardian block and consent
- **Tags:** sprint-6, guests
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Guardian consent is required for anyone under 18 **today** (§6.4), including a guest who turns 18 before departure. The form must show the block from the typed DOB; the card must follow `is_minor_now` after save.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003 (Harrison & Whitfield). **Guests** tab. Two named guests; `＋ Add guest` is visible (2 of cabin max 3).
2. `＋ Add guest`. Edit the new empty slot. First / given names `E2E`, Surname `Minor`. Date of birth `2015-06-01` (under 18 today). Nationality `United States`.
3. The block `UNDER 18 — LEGAL GUARDIAN CONSENT (§6.4)` appears. Leave Guardian consent unchecked. Fill a dummy passport `E2E000018` and expiry `2032-01-01`. `Save guest`.
4. Read the issues warnbox and the new card.
5. **Edit** again. Tick `Guardian consent received (timestamp logged)`. Guardian name `E2E Guardian`, Relationship `Parent`. `Save guest`. Read issues and the card.

## Expected
- [ ] E1 · After the DOB is typed, the guardian heading and name / relationship / consent checkbox are visible (client preview).
- [ ] E2 · After save without consent: toast `Guest saved`. Warnbox includes `✕ E2E Minor is under 18 — guardian consent required (§6.4).` Card has `MINOR` and `Guardian consent missing`.
- [ ] E3 · After save with consent: that issue is gone. Card shows `Guardian E2E Guardian ✓`.

## Notes
Cabin max is engine settings `guests.max_per_cabin` (3). Do not use Leon on 0005 — that minor already has consent. Dummy names and passport only.
