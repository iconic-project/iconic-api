# INBOX-02 · A staff reply is threaded to the inbound message
- **Tags:** sprint-15, crm
- **Priority:** P1
- **Batch:** B20
- **Users:** Carolina
- **Start:** reset

## Why
A reply goes to the original sender through the local mailer. The stored outbound row points at the inbound `message_id`. Mailpit is the delivery check. The header check is the database, because Mailpit does not show `In-Reply-To` cleanly.

## Steps
1. Sign in as Carolina. From `iconic-api` run:
   ```bash
   tests/e2e/bin/setup.sh inject-inbound-email whitfield.anna@iconic.test "E2E inbox reply" "Can you hold Suite 03?"
   ```
2. Keep the printed `message_id`. Open `http://localhost:3001/crm/sales/inbox` and open the conversation whose preview is `Can you hold Suite 03?`. The thread header subject is `E2E inbox reply`.
3. In the composer, type `The suite is free for that Sunday.` Click **Send**.
4. From `iconic-api` run `tests/e2e/bin/mail-find.sh --to whitfield.anna@iconic.test --subject "Re: E2E inbox reply"`.

## Expected
- [ ] E1 · The thread shows the new outbound line and the composer is empty. Toast `Reply sent`.
- [ ] E2 · Mailpit has a message to `whitfield.anna@iconic.test` whose subject contains `Re: E2E inbox reply`.
- [ ] E3 · The outbound `in_reply_to` equals the inbound `message_id` from step 1.
- [ ] E4 · History has `conversation.replied`. No `deliveries` row uses that reply subject.

## Cross-checks
- `bin/db-check.sh 'App\Models\Message::query()->where("subject","Re: E2E inbox reply")->where("direction","OUT")->value("in_reply_to")'` equals the `message_id` printed in step 1.
- `bin/db-check.sh 'App\Models\ChangeHistory::query()->where("event","conversation.replied")->where("subject_type","conversation")->exists()'` → true.
- `bin/db-check.sh 'App\Models\Delivery::query()->where("subject","Re: E2E inbox reply")->exists()'` → false.

## Notes
`ConversationReplyMail` is queued. Horizon must be running before step 4. Do not send the reply to any address outside `@iconic.test`. This scenario injects its own message so it still works after `reset.sh`.
