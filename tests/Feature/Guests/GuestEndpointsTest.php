<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PngCategory;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function guestCabin(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $ownerId ?? managerUser()->id,
        'status' => BookingStatus::PendingPayment,
        'adults' => 2,
        'children' => 0,
    ]);
}

test('an empty slot can be added and listed with a pending png category', function (): void {
    $actor = managerUser();
    $booking = guestCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
        ->assertCreated()
        ->assertJsonPath('is_lead', true)
        ->assertJsonPath('position', 1)
        ->assertJsonPath('complete', false)
        ->assertJsonPath('png_category', PngCategory::Pending->value)
        ->assertJsonPath('png_fee', null)
        ->assertJsonPath('display_name', 'Guest 1');

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('complete_count', 0)
        ->assertJsonPath('png_pending_count', 1)
        ->assertJsonPath('png_known_total', 0)
        ->assertJsonPath('max', app(CurrentConfig::class)->engineSettings()->guests->maxPerCabin)
        ->assertJsonPath('can_add', true)
        ->assertJsonPath('issues', []);
});

test('sales exec sees a masked passport and on_file notes', function (): void {
    $owner = salesExecUser();
    $ops = adminUser();
    $booking = guestCabin($owner->id);

    $this->actingAs($ops)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Julia',
            'last_name' => 'Brandt',
            'dob' => '1981-06-03',
            'nationality' => 'DE',
            'passport_no' => 'C4F7K8Q1R',
            'passport_expiry' => '2031-05-01',
            'insurance_declared' => true,
            'medical_note' => 'penicillin',
        ])
        ->assertCreated()
        ->assertJsonPath('passport_no', 'C4F7K8Q1R')
        ->assertJsonPath('medical_note.value', 'penicillin')
        ->assertJsonPath('medical_note.on_file', true);

    $guestId = Guest::query()->where('booking_id', $booking->id)->value('id');

    $this->actingAs($owner)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk()
        ->assertJsonPath('data.0.passport_no', '•••• Q1R')
        ->assertJsonPath('data.0.medical_note.value', null)
        ->assertJsonPath('data.0.medical_note.on_file', true)
        ->assertJsonPath('data.0.dob', '1981-06-03')
        ->assertJsonPath('data.0.nationality', 'DE');

    $this->actingAs($owner)
        ->patchJson('/api/rms/guests/'.$guestId, ['passport_no' => ''])
        ->assertOk()
        ->assertJsonPath('passport_no', '•••• Q1R');

    expect(Guest::query()->findOrFail($guestId)->passport_no)->toBe('C4F7K8Q1R');

    $this->actingAs($owner)
        ->patchJson('/api/rms/guests/'.$guestId, ['passport_no' => 'XX9999999'])
        ->assertOk()
        ->assertJsonPath('passport_no', '•••• 999');

    expect(Guest::query()->findOrFail($guestId)->passport_no)->toBe('XX9999999');

    $this->actingAs($owner)
        ->patchJson('/api/rms/guests/'.$guestId, ['medical_note' => 'should not write'])
        ->assertForbidden();

    expect(Guest::query()->findOrFail($guestId)->medical_note)->toBe('penicillin');
});

test('a sales exec who does not own the booking cannot write guests', function (): void {
    $owner = salesExecUser();
    $other = salesExecUser();
    $booking = guestCabin($owner->id);

    $this->actingAs($other)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['first_name' => 'Ada'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');
});

test('a future date of birth and an expiry before the dob are 422', function (): void {
    $actor = managerUser();
    $booking = guestCabin($actor->id);
    $today = BusinessTime::now()->toDateString();
    $tomorrow = CarbonImmutable::parse($today)->addDay()->toDateString();

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['dob' => $tomorrow])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dob']);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'dob' => '1990-01-01',
            'passport_expiry' => '1989-12-31',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['passport_expiry']);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['nationality' => 'USA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nationality']);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', ['nationality' => 'ZZ'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nationality']);
});

test('issues never block a save', function (): void {
    $actor = managerUser();
    $booking = guestCabin($actor->id);
    $booking->update(['status' => BookingStatus::Confirmed]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Tiny',
            'last_name' => 'Guest',
            'dob' => '2024-01-01',
            'nationality' => 'US',
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk()
        ->assertJsonPath('issues.0.code', 'under_min_age')
        ->assertJsonPath('total', 1);
});

test('the bookings index exposes guests_summary without loading rows', function (): void {
    $actor = managerUser();
    $booking = guestCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'dob' => '1970-01-01',
            'nationality' => 'GB',
            'passport_no' => 'AB1234567',
            'passport_expiry' => '2030-01-01',
            'insurance_declared' => true,
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('guests_summary.complete', 1)
        ->assertJsonPath('guests_summary.total', 1);
});

test('png uses the galapagos calendar date at 23:30 galt not the utc next day', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 23:30:00', 'Pacific/Galapagos'));

    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2026-09-21');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Edge',
            'last_name' => 'Case',
            'dob' => '2013-09-22',
            'nationality' => 'US',
        ])
        ->assertCreated()
        ->assertJsonPath('age_at_departure', 12)
        ->assertJsonPath('png_category', PngCategory::Foreign12AndUnder->value)
        ->assertJsonPath('is_minor_now', true);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Almost',
            'last_name' => 'Adult',
            'dob' => '2008-09-22',
        ])
        ->assertCreated()
        ->assertJsonPath('is_minor_now', true);

    Carbon::setTestNow();
});
