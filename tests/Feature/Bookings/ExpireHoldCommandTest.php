<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingStatus;
use App\Models\RoomNightClaim;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('inventory:expire-hold releases the hold in testing', function (): void {
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture()),
        managerUser(),
    );

    $this->artisan('inventory:expire-hold', ['reference' => $booking->request_reference])
        ->assertSuccessful();

    $booking->refresh()->load('bookingRequest');
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->bookingRequest?->hold_expired_at)->not->toBeNull();
    expect(RoomNightClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')->count())->toBe(0);
});

test('inventory:expire-hold refuses in production', function (): void {
    $previous = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $this->artisan('inventory:expire-hold', ['reference' => 'ANK-R-2026-0041'])
            ->assertFailed();
    } finally {
        $this->app['env'] = $previous;
    }
});
