<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\ManifestsDue;
use Illuminate\Console\Command;

final class ManifestsDueCommand extends Command
{
    protected $signature = 'iconic:manifests-due';

    protected $description = 'Issue due manifests, warn on missing passenger data, and chase it once before sailing';

    public function handle(ManifestsDue $manifests): int
    {
        $manifests->run();
        $this->info('Manifests checked.');

        return self::SUCCESS;
    }
}
