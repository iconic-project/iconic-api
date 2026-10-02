<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\DocumentCheck;
use Illuminate\Console\Command;

final class DocumentCheckCommand extends Command
{
    protected $signature = 'iconic:document-check';

    protected $description = 'Re-queue one failed automatic document send and never issue a new version';

    public function handle(DocumentCheck $documents): int
    {
        $documents->run();
        $this->info('Document versions checked.');

        return self::SUCCESS;
    }
}
