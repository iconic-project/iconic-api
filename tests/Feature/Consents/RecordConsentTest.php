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

function recordConsentCabin(): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => managerUser()->id,
        'status' => BookingStatus::Confirmed,
    ]);
}

test('recording the same version again is a no-op', function (): void {
    $booking = recordConsentCabin();
    $actor = managerUser();

    $first = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Terms,
        ConsentSource::Staff,
        howObtained: 'signed PDF',
        actor: $actor,
    );

    $second = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Terms,
        ConsentSource::Staff,
        howObtained: 'recorded call',
        actor: $actor,
    );

    expect($second->id)->toBe($first->id);
    expect(Consent::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'consent.recorded')->count())->toBe(1);
});

test('a new version inserts a new row', function (): void {
    $booking = recordConsentCabin();

    app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Terms,
        ConsentSource::PaymentLink,
        ip: '73.1.41.1',
        version: 'v2025.9',
    );

    $second = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Terms,
        ConsentSource::PaymentLink,
        ip: '73.1.41.1',
    );

    expect(Consent::query()->where('booking_id', $booking->id)->count())->toBe(2);
    expect($second->version)->toBe('v2026.1 (text pending LEG-001)');
});

test('accept then withdraw then re-accept of the same version inserts a new row', function (): void {
    $booking = recordConsentCabin();
    $actor = managerUser();

    $first = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Marketing,
        ConsentSource::Staff,
        howObtained: 'form at reception',
        actor: $actor,
    );

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Marketing,
        'version' => $first->version,
        'withdrawn' => true,
        'source' => ConsentSource::Staff,
        'how_obtained' => 'email unsubscribe',
    ]);

    $again = app(RecordConsent::class)->handle(
        $booking,
        ConsentDocument::Marketing,
        ConsentSource::Staff,
        howObtained: 'form at reception again',
        actor: $actor,
    );

    expect($again->id)->not->toBe($first->id);
    expect($again->withdrawn)->toBeFalse();
    expect($again->version)->toBe($first->version);
    expect(Consent::query()->where('booking_id', $booking->id)->where('document', ConsentDocument::Marketing)->count())->toBe(3);
    expect(ChangeHistory::query()->where('event', 'consent.recorded')->count())->toBe(2);
});
