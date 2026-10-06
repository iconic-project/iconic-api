<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Hotel mode is the only seed mode. The historical yacht fixture stays
     * on disk under docs/requirements/examples and is not loaded.
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);
        $this->call(ConfigSeeder::class);
        $this->call(SegmentsSeeder::class);
        $this->call(JourneysSeeder::class);
        $this->call(MessageTemplatesSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call(DemoUsersSeeder::class);
        $this->call(HotelSeeder::class);
    }
}
