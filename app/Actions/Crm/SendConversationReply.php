<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Action;
use App\Enums\MessageDirection;
use App\Mail\Crm\ConversationReplyMail;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\History\History;
use App\Support\Mail\QuotedReply;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SendConversationReply extends Action
{
    public function handle(Conversation $conversation, string $body, User $actor): Message
    {
        $message = $this->transaction(fn (): Message => $this->write($conversation, trim($body), $actor));

        Mail::queue(new ConversationReplyMail($message));

        return $message;
    }

    private function write(Conversation $conversation, string $body, User $actor): Message
    {
        $inbound = $conversation->messages()
            ->where('direction', MessageDirection::In->value)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if (! $inbound instanceof Message || $inbound->message_id === null || $inbound->from === '') {
            throw ValidationException::withMessages([
                'message' => 'There is no inbound message to reply to.',
            ]);
        }

        $from = (string) config('mail.from.address');
        $at = strrchr($from, '@');
        $host = is_string($at) && $at !== '@' ? substr($at, 1) : 'iconic.local';
        $messageId = '<'.Str::uuid()->toString().'@'.$host.'>';
        $subject = str_starts_with(strtolower($conversation->subject), 're:')
            ? $conversation->subject
            : 'Re: '.$conversation->subject;

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Out,
            'from' => $from,
            'to' => [$inbound->from],
            'subject' => mb_substr($subject, 0, 500),
            'body_html' => QuotedReply::htmlFromText($body),
            'body_text' => $body,
            'message_id' => $messageId,
            'in_reply_to' => $inbound->message_id,
            'sent_at' => now(),
            'staff_id' => $actor->id,
        ]);

        $conversation->unread = false;
        $conversation->last_message_at = $message->sent_at;
        $conversation->save();

        History::record($conversation, 'conversation.replied', after: [
            'direction' => MessageDirection::Out->value,
            'message_id' => $messageId,
            'in_reply_to' => $inbound->message_id,
        ], actor: $actor);

        return $message;
    }
}
