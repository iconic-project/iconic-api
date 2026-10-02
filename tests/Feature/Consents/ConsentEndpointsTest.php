<?php

declare(strict_types=1);

use App\Actions\Consents\RecordConsent;
use App\Enums\BookingStatus;
use App\Enums\ConsentDocument;
use App\Enums\ConsentSource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Consent;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function consentCabin(?int $ownerId = null, BookingStatus $status = BookingStatus::Confirmed): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $ownerId ?? managerUser()->id,
        'status' => $status,
        'adults' => 2,
        'children' => 0,
    ]);
}

test('staff can record a consent on their own booking', function (): void {
    $actor = salesExecUser();
    $booking = consentCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/consents', [
            'document' => ConsentDocument::Privacy->value,
            'how_obtained' => 'signed PDF by email',
        ])
        ->assertCreated()
        ->assertJsonPath('document', ConsentDocument::Privacy->value)
        ->assertJsonPath('source', ConsentSource::Staff->value)
        ->assertJsonPath('ip', null)
        ->assertJsonPath('how_obtained', 'signed PDF by email')
        ->assertJsonPath('version', 'v2026.1 (pending LEG-002)')
        ->assertJsonPath('withdrawn', false);

    $entry = ChangeHistory::query()->where('event', 'consent.recorded')->latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->subject_id)->toBe($booking->id);
    expect($entry?->reason)->toBe('signed PDF by email');
    expect($entry?->context['what'] ?? null)->toBe('Consent recorded — Privacy policy');
});

test('staff recording requires how_obtained and ignores a client version', function (): void {
    $actor = managerUser();
    $booking = consentCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/consents', [
            'document' => ConsentDocument::Terms->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['how_obtained']);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/consents', [
            'document' => ConsentDocument::Terms->value,
            'how_obtained' => 'recorded call',
            'version' => 'forged',
            'source' => ConsentSource::Engine->value,
            'ip' => '1.2.3.4',
        ])
        ->assertCreated()
        ->assertJsonPath('version', 'v2026.1 (text pending LEG-001)')
        ->assertJsonPath('source', ConsentSource::Staff->value)
        ->assertJsonPath('ip', null);
});

test('a sales exec who does not own the booking cannot record a consent', function (): void {
    $owner = salesExecUser();
    $other = salesExecUser();
    $booking = consentCabin($owner->id);

    $this->actingAs($other)
        ->postJson('/api/rms/bookings/'.$booking->id.'/consents', [
            'document' => ConsentDocument::Terms->value,
            'how_obtained' => 'recorded call',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');
});

test('get lists five documents and marks an older version outdated', function (): void {
    $actor = managerUser();
    $booking = consentCabin($actor->id);

    app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Terms,
        ConsentSource::PaymentLink,
        ip: '73.1.41.10',
        version: 'v2025.9',
    );

    $response = $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/consents')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(5);
    expect($response->json('data.0.document'))->toBe(ConsentDocument::Terms->value);
    expect($response->json('data.0.required'))->toBeTrue();
    expect($response->json('data.0.outdated'))->toBeTrue();
    expect($response->json('data.0.consent.version'))->toBe('v2025.9');
    expect($response->json('data.4.document'))->toBe(ConsentDocument::Marketing->value);
    expect($response->json('data.4.required'))->toBeFalse();
    expect($response->json('data.4.consent'))->toBeNull();
    expect($response->json('data.4.outdated'))->toBeFalse();
});

test('get latest accepted ignores a withdrawn row', function (): void {
    $actor = managerUser();
    $booking = consentCabin($actor->id);

    $accepted = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Marketing,
        ConsentSource::PaymentLink,
        ip: '73.1.41.11',
    );

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'version' => $accepted->version,
        'withdrawn' => true,
        'source' => ConsentSource::Staff,
        'how_obtained' => 'email unsubscribe',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/consents')
        ->assertOk()
        ->assertJsonPath('data.4.consent.id', $accepted->id)
        ->assertJsonPath('data.4.consent.withdrawn', false);
});
