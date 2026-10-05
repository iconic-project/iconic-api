<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a later publish of png amounts does not rewrite stored fees', function (): void {
    $actor = adminUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'dob' => '1970-01-01',
            'nationality' => 'GB',
        ])
        ->assertCreated()
        ->assertJsonPath('png_category', PngCategory::ForeignOver12->value)
        ->assertJsonPath('png_fee', 200);

    $guest = Guest::query()->where('booking_id', $booking->id)->firstOrFail();
    $current = app(CurrentConfig::class)->version(ConfigKind::EngineSettings);
    $document = app(CurrentConfig::class)->engineSettings()->toArray();
    $document['fees']['png']['foreign_over_12'] = 250;

    expect(fn () => app(ConfigPublisher::class)->publish(
        ConfigKind::EngineSettings,
        $document,
        $current->version,
        'Sprint 6: png republish must not rewrite stored guest fees',
        $actor,
    ))->toThrow(ValidationException::class);

    expect($guest->fresh()?->png_fee)->toBe(200);
    expect($guest->fresh()?->png_category)->toBe(PngCategory::ForeignOver12);
});

test('moving a booking recomputes stored png from the new departure date', function (): void {
    // 2028-11-12 is the last check-in inside a 730-day horizon from this day.
    Carbon::setTestNow(Carbon::parse('2026-11-13 18:00:00', 'UTC'));

    $actor = adminUser();
    $from = ReservationFixtures::anamaraDeparture('2027-11-07');
    $to = ReservationFixtures::anamaraDeparture('2028-11-12');
    $id = $this->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($from, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');
    $booking = Booking::query()->findOrFail($id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Boundary',
            'last_name' => 'Child',
            'dob' => '2015-11-08',
            'nationality' => 'US',
        ])
        ->assertCreated()
        ->assertJsonPath('age_at_departure', 11)
        ->assertJsonPath('png_category', PngCategory::Foreign12AndUnder->value)
        ->assertJsonPath('png_fee', 100);

    $preview = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $to->id,
            'cabin_code' => 'S1',
        ])
        ->assertOk()
        ->json();

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $to->id,
            'cabin_code' => 'S1',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk();

    $guest = Guest::query()->where('booking_id', $booking->id)->firstOrFail();

    expect($guest->png_category)->toBe(PngCategory::ForeignOver12);
    expect($guest->png_fee)->toBe(200);
});
