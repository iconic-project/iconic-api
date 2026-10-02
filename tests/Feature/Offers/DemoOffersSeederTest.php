<?php

declare(strict_types=1);

use App\Enums\OfferStatus;
use App\Models\Offer;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoOffersSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoInventorySeeder::class);
});

test('the placeholder offers are live and approved', function (): void {
    $this->seed(DemoOffersSeeder::class);

    $codes = Offer::query()->orderBy('reference')->pluck('code')->all();

    expect($codes)->toBe([
        'OPENING-27',
        'VIRTUOSO-EARLY',
        'ICONIC10',
        'ADVISOR5',
        'EARLY500',
        'SHOULDER15',
        'EARLY10-1205',
        'LAST12',
        'EARLY10-0116',
    ]);

    expect(Offer::query()->where('status', OfferStatus::Live)->count())->toBe(9);
    expect(Offer::query()->where('code', 'ICONIC10')->firstOrFail()->is_promo_code)->toBeTrue();
    expect(Offer::query()->where('code', 'VIRTUOSO-EARLY')->firstOrFail()->channel->value)->toBe('B2B');
    expect(Offer::query()->whereNotNull('first_live_at')->count())->toBe(9);
});
