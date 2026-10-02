<?php

declare(strict_types=1);

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a cabin booking cannot exceed guests.max_per_cabin', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);
    $max = app(CurrentConfig::class)->engineSettings()->guests->maxPerCabin;

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($actor)
            ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
            ->assertCreated();
    }

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk()
        ->assertJsonPath('max', $max)
        ->assertJsonPath('can_add', false);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.guests.0', 'A suite takes up to '.$max.' guests.');
});

test('a charter cannot exceed guests.max_per_property', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => null,
        'type' => BookingType::Charter,
        'owner_id' => $actor->id,
    ]);
    $max = app(CurrentConfig::class)->engineSettings()->guests->maxPerProperty;

    for ($i = 0; $i < $max; $i++) {
        $this->actingAs($actor)
            ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
            ->assertCreated();
    }

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.guests.0', 'Charter capacity is '.$max.' PAX.');
});

test('only an empty non-lead guest can be removed', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['first_name' => 'Lead'])
        ->assertCreated();
    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['first_name' => 'Named'])
        ->assertCreated();
    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
        ->assertCreated();

    $lead = Guest::query()->where('booking_id', $booking->id)->where('is_lead', true)->firstOrFail();
    $named = Guest::query()->where('booking_id', $booking->id)->where('first_name', 'Named')->firstOrFail();
    $empty = Guest::query()->where('booking_id', $booking->id)->where('first_name', '')->where('is_lead', false)->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson('/api/rms/guests/'.$lead->id)
        ->assertUnprocessable();
    $this->actingAs($actor)
        ->deleteJson('/api/rms/guests/'.$named->id)
        ->assertUnprocessable();
    $this->actingAs($actor)
        ->deleteJson('/api/rms/guests/'.$empty->id)
        ->assertNoContent();
});
