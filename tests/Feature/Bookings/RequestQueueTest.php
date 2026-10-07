<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Models\ChangeHistory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the queue lists requests newest first with meta.rules', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();

    $first = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
        ]),
        $actor,
    );
    $this->travel(2)->hours();
    $second = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 1]],
            'preferred_channel' => 'WHATSAPP',
            'travel_advisor' => true,
        ]),
        $actor,
    );

    $response = $this->actingAs($actor)
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id)
        ->assertJsonPath('data.0.party', '2 adults · 1 room')
        ->assertJsonPath('data.0.travel_advisor', true)
        ->assertJsonPath('data.0.contact.preferred_channel', 'WHATSAPP')
        ->assertJsonPath('data.0.hold.expired', false)
        ->assertJsonPath('data.0.sla.breached', false)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.rules.near_term_business_hours', 48)
        ->assertJsonPath('meta.rules.long_lead_business_days', 5)
        ->assertJsonPath('meta.rules.near_term_max_days', 120)
        ->assertJsonPath('meta.rules.response_hours', 24)
        ->assertJsonPath('meta.rules.business_day_minutes', 540)
        ->assertJsonPath('meta.rules.deposit_pct', 30);

    expect($response->json('data.0.hold.remaining_business_minutes'))
        ->toBeInt()
        ->toBeGreaterThan(0);

    $this->actingAs($actor)
        ->getJson('/api/rms/requests?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);
});

test('confirm converts the hold and release requires a reason', function (): void {
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture(), [
            'preferred_channel' => 'WHATSAPP',
        ]),
        managerUser(),
    );

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::PendingPayment->value);

    expect($booking->fresh()->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);
    expect(ChangeHistory::query()->where('event', 'booking.status_changed')->latest('id')->value('after')['what'] ?? '')
        ->toBe('Status REQUESTED → PENDING PAYMENT · deposit link to be sent via WHATSAPP');

    $other = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture(), [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
        ]),
        managerUser(),
    );

    $this->actingAs($other->owner)
        ->postJson('/api/rms/requests/'.$other->id.'/release', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($other->owner)
        ->postJson('/api/rms/requests/'.$other->id.'/release', ['reason' => 'Client withdrew'])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Released->value);

    expect(ChangeHistory::query()->where('event', 'booking.released')->where('subject_id', $other->id)->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'booking.released')->value('after')['client'] ?? '')->toBe($other->contact->name);
});

test('own-records blocks lucia from acting on mateo\'s request', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture()),
        $mateo,
    );

    $this->actingAs($lucia)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm')
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');

    $this->actingAs($lucia)
        ->postJson('/api/rms/requests/'.$booking->id.'/release', ['reason' => 'No'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');
});

test('the holds list shows TEC-004 rule text from the document', function (): void {
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture()),
        managerUser(),
    );

    $this->actingAs($booking->owner)
        ->getJson('/api/rms/holds')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'REQUEST')
        ->assertJsonPath('data.0.reference', $booking->request_reference)
        ->assertJsonPath('data.0.booking_id', $booking->id)
        ->assertJsonPath('data.0.rule', 'TEC-004 · 5 business days (long-lead)')
        ->assertJsonPath('meta.rules.business_day_minutes', 540);
});

test('cfo can read holds remaining minutes and business-day rules', function (): void {
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture()),
        managerUser(),
    );

    $response = $this->actingAs(externalFinanceUser(['email' => 'cfo@iconic.test']))
        ->getJson('/api/rms/holds')
        ->assertOk()
        ->assertJsonPath('data.0.booking_id', $booking->id)
        ->assertJsonPath('meta.rules.business_day_minutes', 540);

    expect($response->json('data.0.remaining_business_minutes'))
        ->toBeInt()
        ->toBeGreaterThan(0);
});

test('the holds list filters by departure date and exposes the booking id', function (): void {
    $actor = managerUser();
    $november = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture('2027-11-07')),
        $actor,
    );
    $december = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture('2027-12-19', festive: true), [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
        ]),
        $actor,
    );

    $this->actingAs($actor)
        ->getJson('/api/rms/holds?from=2027-12-01&to=2027-12-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.booking_id', $december->id)
        ->assertJsonPath('data.0.reference', $december->request_reference);

    $this->actingAs($actor)
        ->getJson('/api/rms/holds?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.booking_id', $november->id);
});
