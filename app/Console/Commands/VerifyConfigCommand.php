<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Config\ConfigVerifier;
use Illuminate\Console\Command;

final class VerifyConfigCommand extends Command
{
    protected $signature = 'iconic:config-verify';

    protected $description = 'Validate the current published configuration documents against their rules';

    public function handle(ConfigVerifier $verifier): int
    {
        $report = $verifier->report();

        if (! $report->ok()) {
            foreach ($report->failures as $failure) {
                $this->error($failure->line());
            }

            return self::FAILURE;
        }

        foreach ($report->valid as $line) {
            $this->info($line);
        }

        return self::SUCCESS;
    }
}
