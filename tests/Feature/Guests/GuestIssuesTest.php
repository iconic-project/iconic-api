<?php

declare(strict_types=1);

use App\Actions\Consents\RecordConsent;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ConsentDocument;
use App\Enums\ConsentSource;
use App\Models\Booking;
use App\Support\Dates\Format;
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

function issuesCabin(array $booking = []): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $actor = managerUser();

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
        'adults' => 2,
        'children' => 0,
        ...$booking,
    ]);
}

test('each guest issue is returned with prototype wording', function (): void {
    $booking = issuesCabin(['children' => 0]);
    $actor = $booking->owner;
    $return = Format::calendar($booking->departure->returnDate());

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Tiny',
            'last_name' => 'One',
            'dob' => '2024-01-01',
            'nationality' => 'US',
            'passport_expiry' => '2027-11-10',
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Leon',
            'last_name' => 'Brandt',
            'dob' => '2015-03-02',
            'nationality' => 'DE',
        ])
        ->assertCreated();

    $response = $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk();

    $codes = collect($response->json('issues'))->pluck('code')->all();

    expect($codes)->toContain('under_min_age');
    expect($codes)->toContain('guardian_consent_missing');
    expect($codes)->toContain('passport_expired');
    expect($codes)->toContain('insurance_undeclared');
    expect($codes)->toContain('children_mismatch');
    expect($codes)->toContain('consents_missing');

    $messages = collect($response->json('issues'))->pluck('message')->all();
    expect($messages)->toContain('Tiny One is 3 on departure — minimum age is 6 (OPS-004).');
    expect($messages)->toContain('Leon Brandt is under 18 — guardian consent required (§6.4).');
    expect($messages)->toContain("Tiny One's passport expires before the return date ({$return}).");
    expect($messages)->toContain('Tiny One has no travel-insurance declaration (OPS-005).');
    expect($messages)->toContain('Guests aged 6–17: 1 · priced as children: 0 — check the quote.');
    expect($messages)->toContain(
        'Missing consent records: Terms & Conditions, Cancellation policy, Privacy policy, Travel insurance declaration.',
    );
});

test('a minor today who turns 18 before departure still needs guardian consent', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Pacific/Galapagos'));

    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $actor = managerUser();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Almost',
            'last_name' => 'Adult',
            'dob' => '2008-10-01',
        ])
        ->assertCreated()
        ->assertJsonPath('is_minor_now', true)
        ->assertJsonPath('age_at_departure', 19);

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->assertOk()
        ->assertJsonFragment(['code' => 'guardian_consent_missing']);

    Carbon::setTestNow();
});

test('insurance is not warned on a pending payment booking', function (): void {
    $booking = issuesCabin(['status' => BookingStatus::PendingPayment]);
    $actor = $booking->owner;

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])
        ->assertCreated();

    $codes = collect($this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->json('issues'))->pluck('code');

    expect($codes)->not->toContain('insurance_undeclared');
    expect($codes)->not->toContain('consents_missing');
});

test('children mismatch is not raised on a charter', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $actor = managerUser();
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => null,
        'type' => BookingType::Charter,
        'owner_id' => $actor->id,
        'status' => BookingStatus::Confirmed,
        'adults' => 0,
        'children' => 0,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Child',
            'last_name' => 'Rate',
            'dob' => '2014-01-01',
            'nationality' => 'US',
        ])
        ->assertCreated();

    $codes = collect($this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->json('issues'))->pluck('code');

    expect($codes)->not->toContain('children_mismatch');
});

test('missing marketing is never a consent warning and an outdated version still counts', function (): void {
    $booking = issuesCabin();
    $actor = $booking->owner;

    foreach ([
        ConsentDocument::Terms,
        ConsentDocument::Cancellation,
        ConsentDocument::Privacy,
        ConsentDocument::Insurance,
    ] as $document) {
        app(RecordConsent::class)->handle(
            $booking,
            $document,
            ConsentSource::PaymentLink,
            ip: '73.1.41.9',
            version: 'old',
        );
    }

    $codes = collect($this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/guests')
        ->json('issues'))->pluck('code');

    expect($codes)->not->toContain('consents_missing');
});
