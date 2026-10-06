# CRM-04 · Merge two duplicates; undo restores what moved
- **Tags:** sprint-9, crm
- **Priority:** P1
- **Users:** Carolina
- **Start:** reset

## Why
A merge must move every booking, request, waitlist entry and event onto the older contact, keep the old id as an alias, and undo within 30 days must restore exactly what moved.

## Steps
1. Sign in as `carolina@iconic.test` / `password`. Open `http://localhost:3001/crm/sales/contacts`. Confirm **Possible duplicates** is absent.
2. Search `Whitfield`. Open **Anna Whitfield**. Under **Edit**, set Phone to `+1 650 253 0000` and Country to `US`. `Save`. Record the contact id from the header (`contact_id {id}`).
3. Search `Osei`. Open **K. Osei**. Set the same phone and country. `Save`. Record that id.
4. Close the drawer (or reload `/crm/sales/contacts`). Read **Possible duplicates**.
5. Click **Merge** on the Anna Whitfield / K. Osei pair. Read the survivor copy. Type reason `E2E CRM-04 merge waitlist pair`. `Merge`.
6. Open `?open=` of the **loser** id (the higher id). Read the banner.
7. On the survivor drawer, find **Undo** on the merge timeline item. Type reason `E2E CRM-04 undo`. Confirm.
8. Search both names. Confirm both rows are back.

## Expected
- [ ] E1 · After both saves: **Possible duplicates** shows the two names and reason **Same phone**. ⚠ UNVERIFIED — `phone_e164` `+16502530000` (Pest `ContactDuplicatesTest`).
- [ ] E2 · Merge modal title `Merge contacts`. Copy: `The older contact (lower id) survives. Bookings, group coordinator, waitlist entries and charter enquiries move onto that contact. Empty email, phone, country and touches on the survivor are filled; existing values are kept.` Reason required. Toast `Contacts merged`.
- [ ] E3 · The loser leaves the list. `?open=` of the loser shows banner **Merged into {survivor name}** (the survivor’s current name). The URL becomes the survivor’s id. ⚠ UNVERIFIED — task 06 used ids 19 and 20 once; do not require those ids.
- [ ] E4 · Waitlist entries that pointed at the loser now sit on the survivor (Holds & Waitlist still lists Anna Whitfield and K. Osei only if undo has not run — after merge, both categories resolve to the survivor). ⚠ UNVERIFIED — `ContactReferences`.
- [ ] E5 · Undo reason required. Hint: `Recorded rows return to the contact they left if they still point at the survivor.` Toast `Merge undone`. K. Osei is back on the list. The loser’s waitlist row points at the restored contact.

## Cross-checks
- After merge: `bin/db-check.sh 'App\Models\Contact::query()->where("email","k.osei@iconic.test")->value("merged_into_id")'` → the survivor id (not null).
- After undo: that `merged_into_id` is null again.

## Notes
Fresh seed has no pairs — this scenario creates one with a shared valid phone (`+1 650 253 0000`, country `US`). Do not hard-require contact ids 19 and 20. Stop if Save does not persist `phone_e164` (then the duplicates panel stays hidden — **BUG**).
