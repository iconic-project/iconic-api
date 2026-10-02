<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\ClaimKind;
use App\Enums\DepartureStatus;
use App\Enums\TaskKind;
use App\Models\AgencyUser;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\CheckoutSession;
use App\Models\CrmTask;
use App\Models\Departure;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use App\Support\Portal\PortalRequestWords;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function portalRequestBody(Departure $departure, array $overrides = []): array
{
    return array_merge([
        'departure_id' => $departure->id,
        'category' => CabinCategory::Suite->value,
        'cabins' => [
            ['adults' => 2, 'children' => 0],
        ],
        'client' => [
            'name' => 'Elena Guest',
            'email' => 'elena-'.uniqid().'@guest.test',
        ],
        'notes' => 'Anniversary on board',
        'client_of_record' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postPortalRequest(AgencyUser $user, array $payload): TestResponse
{
    return withPortalCsrf()
        ->actingAs($user, 'agency')
        ->postJson('/api/portal/requests', $payload);
}

function asStaff(): void
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
}

test('a portal request matches an engine request and freezes the agency commission without a hold', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $agency = approvedAgency(['name' => 'Blue Latitude', 'commission_pct' => 10]);
    $user = agencyUser(['name' => 'Ana Agent', 'email' => 'ana@agency.test'], $agency);
    $hours = app(CurrentConfig::class)->businessRules()->sla->responseHours;
    $body = portalRequestBody($departure);

    $response = postPortalRequest($user, $body);

    $response->assertCreated()
        ->assertJsonPath('status', BookingStatus::Requested->value)
        ->assertJsonPath('message', PortalRequestWords::forStatus(BookingStatus::Requested, $hours));

    $reference = $response->json('references.0');
    $booking = Booking::query()->where('request_reference', $reference)->firstOrFail();

    expect($booking->reference)->toBeNull()
        ->and($booking->request_reference)->toStartWith('ANK-R-')
        ->and($booking->status)->toBe(BookingStatus::Requested)
        ->and($booking->agency_id)->toBe($agency->id)
        ->and($booking->commission_pct)->toBe(10)
        ->and($booking->commission_approved)->toBeTrue()
        ->and($booking->checkout_session_id)->toBeNull()
        ->and($booking->cabin_id)->not->toBeNull()
        ->and($booking->price_lines)->not->toBeEmpty()
        ->and($booking->total)->toBeGreaterThan(0)
        ->and($booking->deposit_pct)->toBeGreaterThan(0)
        ->and($booking->bookingRequest?->travel_advisor)->toBeTrue()
        ->and($booking->bookingRequest?->notes)->toBe('Anniversary on board')
        ->and($booking->bookingRequest?->sla_due_at?->equalTo(
            $booking->bookingRequest?->submitted_at?->copy()->addHours($hours),
        ))->toBeTrue()
        ->and($booking->guests)->toHaveCount(0)
        ->and($booking->claims)->toHaveCount(0)
        ->and($booking->contact->name)->toBe('Elena Guest')
        ->and($booking->contact->email)->toBe($body['client']['email'])
        ->and($booking->contact->email)->not->toBe($user->email);

    expect(CrmTask::query()->where('kind', TaskKind::RequestResponse)->where('booking_id', $booking->id)->exists())->toBeTrue();
    expect(CabinClaim::query()->where('holder_id', $booking->id)->where('holder_type', $booking->getMorphClass())->count())->toBe(0);

    $listed = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/requests')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $reference)
        ->assertJsonPath('data.0.status', BookingStatus::Requested->value)
        ->assertJsonPath('data.0.lead_guest', 'Elena Guest')
        ->assertJsonPath('data.0.next', $response->json('message'));

    expect(array_keys($listed->json('data.0')))->toBe([
        'id',
        'reference',
        'status',
        'lead_guest',
        'next',
        'payment_state',
        'open_payment_kinds',
    ]);
    expect($listed->json('data.0.id'))->toBe($booking->id);
    expect($listed->json('data.0.open_payment_kinds'))->toBe([]);

    asStaff();

    $this->actingAs(adminUser())
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->assertJsonPath('data.0.source', 'portal')
        ->assertJsonPath('data.0.agency_name', 'Blue Latitude')
        ->assertJsonPath('data.0.display_reference', $reference);

    $agencyHistory = ChangeHistory::query()->where('event', 'portal.request_created')->where('subject_id', $agency->id)->firstOrFail();
    $bookingHistory = ChangeHistory::query()->where('event', 'booking.requested')->where('subject_id', $booking->id)->firstOrFail();

    expect($agencyHistory->actor_label)->toBe('Ana Agent (ana@agency.test)')
        ->and($agencyHistory->context['agency_user_id'])->toBe($user->id)
        ->and($bookingHistory->actor_label)->toBe('Ana Agent (ana@agency.test)')
        ->and($bookingHistory->context['agency_user_id'])->toBe($user->id);
});

test('two cabins become two bookings in one group and still claim nothing', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $user = agencyUser();

    $response = postPortalRequest($user, portalRequestBody($departure, [
        'cabins' => [
            ['adults' => 2, 'children' => 0],
            ['adults' => 1, 'children' => 1],
        ],
    ]));

    $response->assertCreated();
    expect($response->json('references'))->toHaveCount(2);

    $bookings = Booking::query()->whereIn('request_reference', $response->json('references'))->get();

    expect($bookings)->toHaveCount(2)
        ->and($bookings->pluck('group_id')->unique())->toHaveCount(1)
        ->and($bookings->first()?->group_id)->not->toBeNull()
        ->and(CabinClaim::query()->count())->toBe(0);
});

test('an over-cap agency lands on ON_HOLD_AGENCY with the existing cap task and alert', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $agency = approvedAgency(['name' => 'Meridian', 'commission_pct' => 15]);
    $user = agencyUser([], $agency);
    $hours = app(CurrentConfig::class)->businessRules()->sla->responseHours;

    $response = postPortalRequest($user, portalRequestBody($departure));

    $response->assertCreated()
        ->assertJsonPath('status', BookingStatus::OnHoldAgency->value)
        ->assertJsonPath('message', PortalRequestWords::forStatus(BookingStatus::OnHoldAgency, $hours));

    $booking = Booking::query()->where('request_reference', $response->json('references.0'))->firstOrFail();

    expect($booking->status)->toBe(BookingStatus::OnHoldAgency)
        ->and($booking->commission_pct)->toBe(15)
        ->and($booking->commission_approved)->toBeFalse()
        ->and($booking->claims)->toHaveCount(0);

    expect(CrmTask::query()->where('kind', TaskKind::CommissionCap)->where('booking_id', $booking->id)->exists())->toBeTrue();
    expect(CrmTask::query()->where('kind', TaskKind::RequestResponse)->where('booking_id', $booking->id)->exists())->toBeFalse();
    expect(Alert::query()->where('kind', AlertKind::CommissionCap)->where('booking_id', $booking->id)->exists())->toBeTrue();
    expect(ChangeHistory::query()->where('event', 'booking.commission_held')->where('subject_id', $booking->id)->exists())->toBeTrue();

    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/requests')
        ->assertOk()
        ->assertJsonPath('data.0.status', BookingStatus::OnHoldAgency->value)
        ->assertJsonPath('data.0.next', $response->json('message'));

    asStaff();

    $ids = $this->actingAs(adminUser())
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->not->toContain($booking->id);
});

test('a sold-out, closed, hidden, or out-of-calendar departure is refused and writes nothing', function (): void {
    $user = agencyUser();

    $full = ReservationFixtures::anamaraDeparture('2027-11-21');
    $full->load('property.cabins');
    $holder = ClaimHolder::query()->create(['reference' => 'BLK', 'name' => 'Taken']);

    DB::transaction(function () use ($full, $holder): void {
        app(ClaimService::class)->claim($full, $full->property->cabins, $holder, ClaimKind::Block);
    });

    $label = app(Availability::class)->forDepartures(collect([$full->fresh(['property.cabins', 'itinerary'])]))[$full->id]->engineLabel['text'];

    $closed = ReservationFixtures::anamaraDeparture('2027-11-28');
    $closed->update(['status' => DepartureStatus::Closed]);

    $hidden = ReservationFixtures::anamaraDeparture('2027-12-05');
    $hidden->update(['status' => DepartureStatus::Hidden]);

    $early = ReservationFixtures::anamaraDeparture('2026-06-07');

    $ownerTaken = ReservationFixtures::anamaraDeparture('2027-12-12');
    $ownerTaken->load('property.cabins');
    $owner = $ownerTaken->property->cabins->first(fn ($cabin) => $cabin->category === CabinCategory::Owner);
    $ownerHolder = ClaimHolder::query()->create(['reference' => 'OWN', 'name' => 'Owner taken']);

    DB::transaction(function () use ($ownerTaken, $owner, $ownerHolder): void {
        app(ClaimService::class)->claim($ownerTaken, collect([$owner]), $ownerHolder, ClaimKind::Block);
    });

    postPortalRequest($user, portalRequestBody($full))
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure.0', $label);

    postPortalRequest($user, portalRequestBody($closed))
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure.0', 'CLOSED — ENQUIRE');

    postPortalRequest($user, portalRequestBody($hidden))->assertNotFound();

    postPortalRequest($user, portalRequestBody($early))->assertNotFound();

    postPortalRequest($user, portalRequestBody($ownerTaken, [
        'category' => CabinCategory::Owner->value,
    ]))
        ->assertConflict()
        ->assertJsonPath('message', 'Cabin unavailable.');

    expect(Booking::query()->count())->toBe(0);
});

test('the portal cannot set a price, a discount, a commission, or another agency', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $user = agencyUser();

    postPortalRequest($user, portalRequestBody($departure, [
        'price' => 1000,
        'discount' => 10,
        'commission_pct' => 5,
        'promo_code' => 'SAVE',
        'agency_id' => 999,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price', 'discount', 'commission_pct', 'promo_code', 'agency_id']);

    postPortalRequest($user, portalRequestBody($departure, [
        'client_of_record' => false,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['client_of_record']);

    expect(Booking::query()->count())->toBe(0);
});

test('an agency only sees its own requests', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $owner = agencyUser();
    $other = agencyUser();

    $created = postPortalRequest($owner, portalRequestBody($departure))->assertCreated();

    $this->actingAs($other, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/requests')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($owner, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/requests')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $created->json('references.0'));
});

test('the RMS request list marks engine, portal, and staff sources', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $agency = approvedAgency(['name' => 'Blue Latitude']);
    $user = agencyUser([], $agency);
    $manager = managerUser();

    $staff = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S8', 'adults' => 2, 'children' => 0]],
        ]),
        $manager,
    );

    $engine = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'OWNER', 'adults' => 2, 'children' => 0]],
        ]),
        $manager,
    );
    $session = CheckoutSession::factory()->create(['departure_id' => $departure->id]);
    Booking::query()->whereKey($engine->id)->update(['checkout_session_id' => $session->id]);

    postPortalRequest($user, portalRequestBody($departure))->assertCreated();

    asStaff();

    $rows = collect($this->actingAs(adminUser())
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->json('data'))->keyBy('id');

    $portalId = Booking::query()->where('agency_id', $agency->id)->value('id');

    expect($rows[$portalId]['source'])->toBe('portal')
        ->and($rows[$portalId]['agency_name'])->toBe('Blue Latitude')
        ->and($rows[$staff->id]['source'])->toBe('rms')
        ->and($rows[$staff->id]['agency_name'])->toBeNull()
        ->and($rows[$engine->id]['source'])->toBe('engine')
        ->and($rows[$engine->id]['agency_name'])->toBeNull();
});
