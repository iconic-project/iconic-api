<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\StripeEvent;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('replay-stripe-checkout settles an open link exactly once when posted twice', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0600']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payment-link', [
            'kind' => PaymentKind::Deposit->value,
        ])
        ->assertCreated();

    $this->artisan('iconic:replay-stripe-checkout', ['reference' => 'ANK-2026-0600'])
        ->assertSuccessful();

    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect(Payment::query()->where('booking_id', $booking->id)->value('status'))->toBe(PaymentStatus::Settled);
    expect(StripeEvent::query()->count())->toBe(1);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('replay-stripe-checkout --expired refuses a payment-link booking', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0601']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payment-link', [
            'kind' => PaymentKind::Deposit->value,
        ])
        ->assertCreated();

    $this->artisan('iconic:replay-stripe-checkout', [
        'reference' => 'ANK-2026-0601',
        '--expired' => true,
    ])->assertFailed();
});

test('replay-stripe-checkout refuses in production', function (): void {
    $previous = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $this->artisan('iconic:replay-stripe-checkout', ['reference' => 'ANK-2026-0600'])
            ->assertFailed();
    } finally {
        $this->app['env'] = $previous;
    }
});
