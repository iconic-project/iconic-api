<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Inventory\BackfillClosedDepartureRestrictions;
use App\Support\Inventory\BackfillRoomNightClaims;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * ICONIC_SEED_MODE=hotel (default) seeds Hotel Demo.
     * ICONIC_SEED_MODE=yacht seeds the yacht inventory. Yacht mode stays available until Sprint 22.
     */
    public function run(): void
    {
        $mode = config('iconic.seed_mode');

        if (! is_string($mode) || ! in_array($mode, ['yacht', 'hotel'], true)) {
            throw new RuntimeException('ICONIC_SEED_MODE must be yacht or hotel.');
        }

        $this->call(RolesSeeder::class);
        $this->call(ConfigSeeder::class);

        if ($mode === 'yacht') {
            $this->call(InventorySeeder::class);
        }

        $this->call(SegmentsSeeder::class);
        $this->call(JourneysSeeder::class);
        $this->call(MessageTemplatesSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call(DemoUsersSeeder::class);

        if ($mode === 'hotel') {
            $this->call(HotelSeeder::class);

            return;
        }

        $this->call(DemoInventorySeeder::class);
        $this->call(DemoOffersSeeder::class);
        $this->call(DemoBookingsSeeder::class);
        $this->call(DemoAgenciesSeeder::class);
        $this->call(DemoRequestsSeeder::class);
        $this->call(DemoGuestsSeeder::class);
        $this->call(DemoConsentsSeeder::class);
        $this->call(DemoExtrasSeeder::class);
        $this->call(DemoDocumentsSeeder::class);

        app(BackfillRoomNightClaims::class)->run();
        BackfillClosedDepartureRestrictions::run();
    }
}
