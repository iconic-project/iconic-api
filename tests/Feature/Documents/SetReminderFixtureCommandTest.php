<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('iconic:set-reminder-fixture sets due date to today plus 21 days', function (): void {
    $booking = Booking::factory()->create([
        'reference' => 'ANK-2026-0908',
    ]);

    $this->artisan('iconic:set-reminder-fixture', ['reference' => 'ANK-2026-0908'])
        ->assertSuccessful();

    $expected = BusinessTime::now()->addDays(21)->toDateString();

    expect($booking->fresh()?->balance_due_date_override?->toDateString())->toBe($expected);
});

test('iconic:set-reminder-fixture refuses in production', function (): void {
    $previous = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $this->artisan('iconic:set-reminder-fixture', ['reference' => 'ANK-2026-0003'])
            ->assertFailed();
    } finally {
        $this->app['env'] = $previous;
    }
});
