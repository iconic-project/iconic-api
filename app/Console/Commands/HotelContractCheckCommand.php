<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Schema\HotelContractCheck;
use Illuminate\Console\Command;

final class HotelContractCheckCommand extends Command
{
    protected $signature = 'iconic:hotel-contract-check';

    protected $description = 'Read-only check that the hotel contract can drop legacy tables and columns';

    public function handle(HotelContractCheck $check): int
    {
        foreach ($check->summary() as $line) {
            $this->line($line);
        }

        $failures = $check->failures();

        if ($failures === []) {
            $this->info('Hotel contract check passed.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return self::FAILURE;
    }
}
