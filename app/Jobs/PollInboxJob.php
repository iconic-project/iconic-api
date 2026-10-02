<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Crm\CaptureInboundMessage;
use App\Models\Message;
use App\Support\Mail\GraphMailbox;
use App\Support\Mail\MailboxReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class PollInboxJob implements ShouldQueue
{
    use Queueable;

    private const MAX_PAGES = 20;

    public function handle(MailboxReader $reader, CaptureInboundMessage $capture): void
    {
        if (config('iconic.inbox.driver') === 'graph' && ! GraphMailbox::credentialsPresent()) {
            Log::warning('Inbox poll skipped: Microsoft Graph keys are empty.');

            return;
        }

        $pageSize = max(1, (int) config('iconic.inbox.page_size', 50));

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $reader->page($page, $pageSize);

            if ($batch === []) {
                return;
            }

            foreach ($batch as $mail) {
                if (Message::query()->where('message_id', $mail->messageId)->exists()) {
                    return;
                }

                $capture->handle($mail);
            }

            if (count($batch) < $pageSize) {
                return;
            }
        }
    }
}
