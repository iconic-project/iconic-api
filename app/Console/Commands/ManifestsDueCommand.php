<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class ManifestsDueCommand extends Command
{
    protected $signature = 'iconic:manifests-due';

    protected $description = 'Retired (09 H15). Does not issue or chase a manifest.';

    public function handle(): int
    {
        $this->info('Manifests are retired (09 H15). Nothing was issued or chased.');

        return self::SUCCESS;
    }
}
