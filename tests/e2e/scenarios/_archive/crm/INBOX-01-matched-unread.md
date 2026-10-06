# INBOX-01 · An inbound email from a known contact lands unread
- **Tags:** sprint-15, crm
- **Priority:** P1
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
Polled mail from a seeded contact opens a thread on that contact. The inbox shows it unread. Opening it marks it read and puts one line on the contact timeline.

## Steps
1. Sign in as Carolina. From `iconic-api` run:
   ```bash
   tests/e2e/bin/setup.sh inject-inbound-email whitfield.anna@iconic.test "E2E inbox matched" "Is the master cabin free on that Sunday?"
   ```
2. Read `contact_id`, `conversation_id`, `unread`, and `message_id` from the printed JSON.
3. Open `http://localhost:3001/crm/sales/inbox`. Find the conversation whose preview is `Is the master cabin free on that Sunday?`.
4. Open that conversation.
5. Open `http://localhost:3001/crm/sales/contacts?open=<contact_id>` and read the timeline.

## Expected
- [ ] E1 · The helper prints `unread` true and a non-null `contact_id`.
- [ ] E2 · The conversation row shows Anna Whitfield, the message preview, an EMAIL tag, and a coral unread dot. The thread header shows subject `E2E inbox matched` and status `OPEN`.
- [ ] E3 · After the thread opens, that row no longer shows the unread dot.
- [ ] E4 · The contact timeline has one line whose kind is `conversation.message`, title is `Email received`, and detail is `E2E inbox matched`.

## Cross-checks
- `bin/db-check.sh 'App\Models\Message::query()->where("subject","E2E inbox matched")->where("direction","IN")->count()'` → `1`.
- `bin/db-check.sh 'App\Models\Conversation::query()->where("subject","E2E inbox matched")->value("unread")'` → false after the thread was opened.

## Notes
The seed inserts no conversations. Other Mailpit mail may also appear as unmatched rows. Match this scenario by the subject. The from-address must stay `@iconic.test`.
