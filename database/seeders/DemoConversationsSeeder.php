<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Crm\CaptureInboundMessage;
use App\Actions\Crm\UpdateConversationStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\History\History;
use App\Support\Mail\InboundMail;
use App\Support\Mail\QuotedReply;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sample inbox threads for the hotel demo contacts. Inbound mail goes through
 * CaptureInboundMessage. Staff replies are stored only — they are not sent.
 */
final class DemoConversationsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $actor = User::query()->where('email', 'carolina@iconic.test')->first();

        if (! $actor instanceof User) {
            throw new RuntimeException('Demo conversation seed needs carolina@iconic.test.');
        }

        $this->inHouseThread($actor);
        $this->requestThread();
        $this->checkedOutThread($actor);
        $this->unlinkedThread();
    }

    private function inHouseThread(User $actor): void
    {
        $from = $this->guestEmail('HTL-019');
        $firstId = '<demo-htl-019-in-1@iconic.test>';
        $replyId = '<demo-htl-019-out-1@iconic.test>';
        $secondId = '<demo-htl-019-in-2@iconic.test>';

        $first = $this->inbound(
            $from,
            'Late dinner',
            $firstId,
            'We are in house in room 208. Can the kitchen keep dinner available after 21:00 tonight?',
            CarbonImmutable::now()->subHours(26),
        );
        $this->reply(
            $first,
            'I have asked the kitchen to keep a table for room 208 after 21:00 tonight.',
            CarbonImmutable::now()->subHours(20),
            $replyId,
            $actor,
        );
        $this->inbound(
            $from,
            'Re: Late dinner',
            $secondId,
            'Perfect. There will be two of us.',
            CarbonImmutable::now()->subHours(2),
            $firstId,
        );
    }

    private function requestThread(): void
    {
        $this->inbound(
            $this->guestEmail('HTL-008'),
            'Request for 2 June',
            '<demo-htl-008-in-1@iconic.test>',
            'I sent a request for 2 June, two adults, room 103. Has it been picked up?',
            CarbonImmutable::now()->subHours(8),
        );
    }

    private function checkedOutThread(User $actor): void
    {
        $from = $this->guestEmail('HTL-011');
        $inboundId = '<demo-htl-011-in-1@iconic.test>';
        $inbound = $this->inbound(
            $from,
            'Invoice for the stay',
            $inboundId,
            'Could you send the invoice for our stay in room 106?',
            CarbonImmutable::now()->subDays(5),
        );
        $this->reply(
            $inbound,
            'The invoice is on the booking. Tell me if you need it sent again.',
            CarbonImmutable::now()->subDays(4),
            '<demo-htl-011-out-1@iconic.test>',
            $actor,
        );

        $conversation = $inbound->conversation()->first();

        if (! $conversation instanceof Conversation) {
            throw new RuntimeException('Demo conversation seed lost the HTL-011 thread.');
        }

        app(UpdateConversationStatus::class)->handle($conversation, ConversationStatus::Closed, $actor);
    }

    private function unlinkedThread(): void
    {
        $this->inbound(
            'demo.unlinked@iconic.test',
            'September dates',
            '<demo-unlinked-in-1@iconic.test>',
            'I would like a room for two adults in September. What dates are open?',
            CarbonImmutable::now()->subHour(),
        );
    }

    private function guestEmail(string $reference): string
    {
        $email = strtolower($reference).'@hotel-demo.test';
        $contact = Contact::query()->where('email', $email)->first();

        if (! $contact instanceof Contact) {
            throw new RuntimeException('Demo conversation seed needs contact '.$email.'.');
        }

        return $email;
    }

    private function inbound(
        string $from,
        string $subject,
        string $messageId,
        string $body,
        CarbonImmutable $sentAt,
        ?string $inReplyTo = null,
    ): Message {
        return app(CaptureInboundMessage::class)->handle(new InboundMail(
            messageId: $messageId,
            from: $from,
            to: [(string) config('mail.reservations')],
            subject: $subject,
            bodyHtml: QuotedReply::htmlFromText($body),
            bodyText: $body,
            inReplyTo: $inReplyTo,
            references: $inReplyTo !== null ? [$inReplyTo] : [],
            sentAt: $sentAt,
        ));
    }

    private function reply(Message $inbound, string $body, CarbonImmutable $sentAt, string $messageId, User $actor): void
    {
        if (Message::query()->where('message_id', $messageId)->exists()) {
            return;
        }

        DB::transaction(function () use ($inbound, $body, $sentAt, $messageId, $actor): void {
            $conversation = $inbound->conversation;

            if (! $conversation instanceof Conversation) {
                throw new RuntimeException('Demo conversation reply has no thread.');
            }

            $subject = str_starts_with(strtolower($conversation->subject), 're:')
                ? $conversation->subject
                : 'Re: '.$conversation->subject;
            $from = (string) config('mail.from.address');

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Out,
                'from' => $from,
                'to' => [$inbound->from],
                'subject' => mb_substr($subject, 0, 500),
                'body_html' => QuotedReply::htmlFromText($body),
                'body_text' => $body,
                'message_id' => $messageId,
                'in_reply_to' => $inbound->message_id,
                'sent_at' => $sentAt,
                'staff_id' => $actor->id,
            ]);

            if ($conversation->last_message_at->lessThan($sentAt)) {
                $conversation->last_message_at = $sentAt;
            }

            $conversation->unread = false;
            $conversation->save();

            History::record($conversation, 'conversation.replied', after: [
                'direction' => MessageDirection::Out->value,
                'message_id' => $messageId,
                'in_reply_to' => $inbound->message_id,
            ], actor: $actor);
        });
    }
}
