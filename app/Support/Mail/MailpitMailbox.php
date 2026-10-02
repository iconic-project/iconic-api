<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Mailpit's HTTP API. Attachments on a message are left unread.
 */
final class MailpitMailbox implements MailboxReader
{
    public function page(int $page, int $pageSize): array
    {
        $base = rtrim((string) config('iconic.inbox.mailpit_url'), '/');
        $list = Http::acceptJson()
            ->baseUrl($base)
            ->get('/api/v1/messages', [
                'start' => $page * $pageSize,
                'limit' => $pageSize,
            ])
            ->throw()
            ->json('messages');

        if (! is_array($list)) {
            return [];
        }

        $mails = [];

        foreach ($list as $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $id = $summary['ID'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $detail = Http::acceptJson()->baseUrl($base)->get('/api/v1/message/'.$id)->throw()->json();

            if (! is_array($detail)) {
                continue;
            }

            $mails[] = $this->message($detail, $summary);
        }

        usort($mails, fn (InboundMail $left, InboundMail $right): int => $right->sentAt <=> $left->sentAt);

        return $mails;
    }

    /**
     * @param  array<mixed>  $detail
     * @param  array<mixed>  $summary
     */
    private function message(array $detail, array $summary): InboundMail
    {
        $headers = is_array($detail['Headers'] ?? null) ? $detail['Headers'] : [];
        $from = $this->address($detail['From'] ?? $summary['From'] ?? null);
        $to = $this->addresses($detail['To'] ?? $summary['To'] ?? null);
        $subject = $this->string($detail['Subject'] ?? $summary['Subject'] ?? '');
        $html = $this->string($detail['HTML'] ?? '');
        $text = $this->string($detail['Text'] ?? '');
        $sentAt = $this->sentAt($detail['Date'] ?? $summary['Created'] ?? null);
        $messageId = MailHeaders::value($headers, 'Message-ID')
            ?? $this->string($detail['MessageID'] ?? $summary['MessageID'] ?? '');

        return InboundMessages::make(
            messageIdHeader: $messageId !== '' ? $messageId : null,
            from: $from,
            to: $to,
            subject: $subject,
            bodyHtml: $html !== '' ? $html : QuotedReply::htmlFromText($text),
            bodyText: $text,
            inReplyTo: MailHeaders::value($headers, 'In-Reply-To'),
            referenceHeaders: $this->headerList(MailHeaders::value($headers, 'References')),
            sentAt: $sentAt,
        );
    }

    private function address(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $address = $value['Address'] ?? $value['address'] ?? '';

        return is_string($address) ? $address : '';
    }

    /**
     * @return list<string>
     */
    private function addresses(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $addresses = [];

        foreach ($value as $item) {
            $address = $this->address($item);

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function sentAt(mixed $value): CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->utc();
        }

        return CarbonImmutable::now('UTC');
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private function headerList(?string $value): array
    {
        return $value !== null && $value !== '' ? [$value] : [];
    }
}
