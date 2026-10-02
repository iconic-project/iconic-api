<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Waitlist\WaitlistOffers;
use Illuminate\Console\Command;

final class WaitlistNotifyCommand extends Command
{
    protected $signature = 'iconic:waitlist-notify';

    protected $description = 'Email the next waitlist entries when a cabin in their category is free';

    public function handle(WaitlistOffers $offers): int
    {
        $sent = $offers->sweep();
        $this->info('Waitlist offers sent: '.$sent.'.');

        return self::SUCCESS;
    }
}
