# GST-07 · Add up to the cabin limit; remove an empty non-lead
- **Tags:** sprint-6, guests
- **Priority:** P2
- **Users:** Carolina
- **Start:** reset

## Why
Slots stop at `guests.max_per_cabin` (3). The lead cannot be removed. Only an empty non-lead slot can be deleted.

## Steps
1. Sign in as Carolina. Open ANK-2026-0003. **Guests** tab. Two named guests (Daniel Harrison lead, Claire Whitfield). `＋ Add guest` is visible.
2. `＋ Add guest`. Read the new card and whether Add is still offered.
3. On the empty non-lead card, **Edit**. `Remove` is visible (name empty, not lead). Click `Remove`.
4. Confirm Daniel (lead) has no `Remove` when edited.

## Expected
- [ ] E1 · After add: toast `Guest slot added`. Third card is `Guest 3 — name pending`. `＋ Add guest` is gone (`can_add` false).
- [ ] E2 · After remove: toast `Empty guest slot removed`. Two cards remain. `＋ Add guest` is visible again.
- [ ] E3 · Editing Daniel Harrison: no `Remove` button (`is_lead`).

## Notes
0005 is already 3/3 — Add is hidden there (GST-01). Max comes from the published engine settings, not a constant.
