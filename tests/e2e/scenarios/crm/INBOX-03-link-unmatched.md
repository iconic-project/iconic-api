# INBOX-03 · An unmatched inbound email can be linked to a contact
- **Tags:** sprint-15, crm
- **Priority:** P1
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
An address with no contact stays unlinked. Linking does not create a contact. After the link, the list and that contact's timeline show the message.

## Steps
1. Sign in as Carolina. From `iconic-api` run:
   ```bash
   tests/e2e/bin/setup.sh inject-inbound-email unlinked.inbox@iconic.test "E2E inbox unlinked" "Hello from an address you do not have."
   ```
2. Confirm the printed `contact_id` is null. Open `http://localhost:3001/crm/sales/inbox`.
3. Open the conversation whose preview is `Hello from an address you do not have.`. In the thread header, click **Link to contact**. Search `Whitfield`. Choose Anna Whitfield.
4. Open `http://localhost:3001/crm/sales/contacts?open=<Anna's id>` and read the timeline. Use the contacts search if the id is not already known. Anna's email is `whitfield.anna@iconic.test`.

## Expected
- [ ] E1 · Before the link, the inbox row shows `unlinked.inbox@iconic.test`, not a contact name.
- [ ] E2 · After the link, that row shows Anna Whitfield. Toast `Contact linked`.
- [ ] E3 · Anna's timeline gains one `conversation.message` line, title `Email received`, detail `E2E inbox unlinked`.
- [ ] E4 · No new contact was created for `unlinked.inbox@iconic.test`.

## Cross-checks
- Before step 3, `bin/db-check.sh 'App\Models\Contact::query()->where("email","unlinked.inbox@iconic.test")->exists()'` → false.
- After step 3, `bin/db-check.sh 'App\Models\Conversation::query()->where("subject","E2E inbox unlinked")->value("contact_id")'` equals Anna Whitfield's contact id.
- After step 3, `bin/db-check.sh 'App\Models\Contact::query()->where("email","unlinked.inbox@iconic.test")->exists()'` → false.

## Notes
`unlinked.inbox@iconic.test` is not in the seed. The helper refuses any other domain. An unlinked thread is on nobody's timeline until this link.
