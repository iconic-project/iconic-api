<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function completeLinkBooking(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-11-12');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S3')?->id,
        'status' => BookingStatus::PendingPayment,
        'owner_id' => $ownerId ?? adminUser()->id,
    ]);
}

test('staff can copy the complete-reservation link and reuse the active url', function (): void {
    $actor = adminUser();
    $booking = completeLinkBooking($actor->id);

    $first = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/complete-link')
        ->assertOk()
        ->json('url');

    expect($first)->toBeString();
    expect($first)->toContain('/complete/');
    expect(BookingAccessToken::query()->where('booking_id', $booking->id)->count())->toBe(1);

    $second = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/complete-link')
        ->assertOk()
        ->json('url');

    expect($second)->toBe($first);
    expect(BookingAccessToken::query()->where('booking_id', $booking->id)->whereNull('revoked_at')->count())->toBe(1);
});

test('own-records blocks copying the complete link', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $booking = completeLinkBooking($mateo->id);

    $this->actingAs($lucia)
        ->postJson('/api/rms/bookings/'.$booking->id.'/complete-link')
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');

    $this->actingAs($mateo)
        ->postJson('/api/rms/bookings/'.$booking->id.'/complete-link')
        ->assertOk();
});

test('a cancelled booking cannot receive a complete link', function (): void {
    $actor = adminUser();
    $booking = completeLinkBooking($actor->id);
    $booking->update(['status' => BookingStatus::Cancelled]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/complete-link')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');
});
