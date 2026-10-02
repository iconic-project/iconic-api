<?php

declare(strict_types=1);

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoBookingsSeeder;
use Database\Seeders\DemoGuestsSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoRequestsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoBookingsSeeder::class);
    $this->seed(DemoRequestsSeeder::class);
});

test('demo guests match seedOps and pad the charter from max_per_property', function (): void {
    $this->seed(DemoGuestsSeeder::class);
    $this->seed(DemoGuestsSeeder::class);

    $brandts = Booking::query()->where('reference', 'ANK-2026-0005')->firstOrFail();
    $brandts->load('guests');

    expect($brandts->guests)->toHaveCount(3);
    expect($brandts->guests->every(fn (Guest $guest): bool => $guest->isComplete()))->toBeTrue();
    expect($brandts->guests[0]->is_lead)->toBeTrue();
    expect($brandts->guests[2]->first_name)->toBe('Leon');
    expect($brandts->guests[2]->guardian_name)->toBe('Markus Brandt');
    expect($brandts->guests[2]->guardian_relationship)->toBe('Father');
    expect($brandts->guests[2]->guardian_consented_at)->not->toBeNull();

    $charter = Booking::query()->where('reference', 'ANK-2026-0012')->firstOrFail();
    $max = app(CurrentConfig::class)->engineSettings()->guests->maxPerProperty;

    expect($charter->type)->toBe(BookingType::Charter);
    expect($charter->adults)->toBe(0);
    expect($charter->guests()->count())->toBe($max);
    expect($charter->guests()->where('is_lead', true)->count())->toBe(1);
    expect($charter->guests()->first()?->first_name)->toBe('Rutger');

    $okafors = Booking::query()->where('reference', 'ANK-2026-0011')->firstOrFail();
    expect($okafors->guests()->complete()->count())->toBe(1);
    expect($okafors->guests()->count())->toBe(2);
});
