<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function billingBooking(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-10-08');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S6')?->id,
        'status' => BookingStatus::Confirmed,
        'owner_id' => $ownerId ?? adminUser()->id,
    ]);
}

test('staff can set billing details and an empty address prints the prototype placeholder', function (): void {
    $actor = adminUser();
    $booking = billingBooking($actor->id);

    $this->actingAs($actor)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/billing', [
            'billing_name' => 'Harrison',
            'billing_address' => "845 Ocean Drive\nMiami, FL 33139, USA",
            'billing_email' => 'accounts@harrison.test',
            'billing_phone' => '+1 305 555 0198',
        ])
        ->assertOk()
        ->assertJsonPath('billing_name', 'Harrison')
        ->assertJsonPath('billing_email', 'accounts@harrison.test');

    expect(ChangeHistory::query()->where('event', 'booking.billing_changed')->count())->toBe(1);

    $this->actingAs($actor)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/billing', [
            'billing_address' => '',
        ])
        ->assertOk()
        ->assertJsonPath('billing_address', null);

    $snapshot = SnapshotFactory::build($booking->fresh(), DocumentKind::Invoice);
    expect($snapshot['billing']['address'])->toBe('[captured at payment link]');
});

test('billing validation and own-records', function (): void {
    $mateo = managerUser();
    $lucia = salesExecUser();
    $booking = billingBooking($mateo->id);

    $this->actingAs($mateo)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/billing', [
            'billing_email' => 'not-an-email',
        ])
        ->assertUnprocessable();

    $this->actingAs($lucia)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/billing', [
            'billing_name' => 'Nope',
        ])
        ->assertForbidden();
});
