<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollInboxJob;
use App\Support\Mail\GraphMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class InboxPollCommand extends Command
{
    protected $signature = 'iconic:inbox-poll';

    protected $description = 'Poll the configured mailbox for inbound CRM mail';

    public function handle(): int
    {
        if (config('iconic.inbox.driver') === 'graph' && ! GraphMailbox::credentialsPresent()) {
            Log::warning('Inbox poll skipped: Microsoft Graph keys are empty.');
            $this->warn('Inbox poll skipped: Microsoft Graph keys are empty.');

            return self::SUCCESS;
        }

        PollInboxJob::dispatch();
        $this->info('Inbox poll queued.');

        return self::SUCCESS;
    }
}
