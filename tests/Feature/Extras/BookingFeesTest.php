<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Guest;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function feesCabin(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-04-02');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'owner_id' => $ownerId ?? adminUser()->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
}

test('switching png collection on stores known fees and reports a pending guest', function (): void {
    $actor = adminUser();
    $booking = feesCabin($actor->id);

    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Markus',
        'last_name' => 'Brandt',
        'dob' => '1979-02-14',
        'nationality' => 'DE',
        'png_fee' => 200,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => '',
        'last_name' => '',
        'png_fee' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/fees', [
            'png_collected' => true,
        ])
        ->assertOk()
        ->assertJsonPath('png_collected', true)
        ->assertJsonPath('png_pending_count', 1)
        ->assertJsonPath('fees_collected_total', 200);

    $entry = ChangeHistory::query()->where('event', 'booking.fees_changed')->latest('id')->first();
    expect($entry?->after['what'] ?? null)->toBe(
        'PNG park entry fee — collected by Iconic (invoiced, due with the balance)',
    );
});

test('tct collection snapshots the published per-person amount times guest records', function (): void {
    $actor = adminUser();
    $booking = feesCabin($actor->id);

    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Anna',
        'dob' => '1970-07-22',
        'nationality' => 'SE',
        'png_fee' => 200,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Erik',
        'dob' => '1968-01-09',
        'nationality' => 'SE',
        'png_fee' => 200,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/fees', [
            'tct_collected' => true,
        ])
        ->assertOk()
        ->assertJsonPath('tct_collected', true)
        ->assertJsonPath('fees_collected_total', 40);

    expect($booking->fresh()?->tct_rate_usd)->toBe(20);
});

test('fees cannot be changed on a cancelled booking', function (): void {
    $actor = adminUser();
    $booking = feesCabin($actor->id);
    $booking->status = BookingStatus::Cancelled;
    $booking->save();

    $this->actingAs($actor)
        ->patchJson('/api/rms/bookings/'.$booking->id.'/fees', [
            'png_collected' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['booking']);
});
