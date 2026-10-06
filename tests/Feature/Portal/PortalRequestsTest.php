<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\TaskKind;
use App\Models\AgencyUser;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CheckoutSession;
use App\Models\CrmTask;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Support\Portal\PortalRequestWords;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(HotelSeeder::class);
    adminUser();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function portalRequestBody(array $overrides = []): array
{
    return array_merge([
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-25',
        'rooms' => [[
            'room_type' => 'FAM',
            'adults' => 2,
            'child_ages' => [],
            'rate_plan' => 'BAR',
        ]],
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
    $agency = approvedAgency(['name' => 'Blue Latitude', 'commission_pct' => 10]);
    $user = agencyUser(['name' => 'Ana Agent', 'email' => 'ana@agency.test'], $agency);
    $hours = app(CurrentConfig::class)->businessRules()->sla->responseHours;
    $body = portalRequestBody();

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
        ->and($booking->check_in->toDateString())->toBe('2026-12-21')
        ->and($booking->check_out->toDateString())->toBe('2026-12-25')
        ->and($booking->roomType?->code)->toBe('FAM')
        ->and($booking->room_id)->not->toBeNull()
        ->and($booking->price_lines)->not->toBeEmpty()
        ->and($booking->total)->toBeGreaterThan(0)
        ->and($booking->deposit_pct)->toBeGreaterThan(0)
        ->and($booking->bookingRequest?->travel_advisor)->toBeTrue()
        ->and($booking->bookingRequest?->notes)->toBe('Anniversary on board')
        ->and($booking->bookingRequest?->sla_due_at?->equalTo(
            $booking->bookingRequest?->submitted_at?->copy()->addHours($hours),
        ))->toBeTrue()
        ->and($booking->guests)->toHaveCount(0)
        ->and($booking->claims)->toHaveCount(4)
        ->and($booking->contact->name)->toBe('Elena Guest')
        ->and($booking->contact->email)->toBe($body['client']['email'])
        ->and($booking->contact->email)->not->toBe($user->email);

    expect(CrmTask::query()->where('kind', TaskKind::RequestResponse)->where('booking_id', $booking->id)->exists())->toBeTrue();
    expect(RoomNightClaim::query()->where('holder_id', $booking->id)->where('holder_type', $booking->getMorphClass())->count())->toBe(4);

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

    $rows = collect($this->actingAs(adminUser())
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->json('data'));
    $portal = $rows->firstWhere('display_reference', $reference);

    expect($portal['source'])->toBe('portal')
        ->and($portal['agency_name'])->toBe('Blue Latitude');

    $agencyHistory = ChangeHistory::query()->where('event', 'portal.request_created')->where('subject_id', $agency->id)->firstOrFail();
    $bookingHistory = ChangeHistory::query()->where('event', 'booking.requested')->where('subject_id', $booking->id)->firstOrFail();

    expect($agencyHistory->actor_label)->toBe('Ana Agent (ana@agency.test)')
        ->and($agencyHistory->context['agency_user_id'])->toBe($user->id)
        ->and($bookingHistory->actor_label)->toBe('Ana Agent (ana@agency.test)')
        ->and($bookingHistory->context['agency_user_id'])->toBe($user->id);
});

test('two rooms become two bookings in one group and each holds its nights', function (): void {
    $user = agencyUser();

    $response = postPortalRequest($user, portalRequestBody([
        'rooms' => [
            ['room_type' => 'FAM', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR'],
            ['room_type' => 'FAM', 'adults' => 2, 'child_ages' => [8], 'rate_plan' => 'BAR'],
        ],
    ]));

    $response->assertCreated();
    expect($response->json('references'))->toHaveCount(2);

    $bookings = Booking::query()->whereIn('request_reference', $response->json('references'))->get();

    expect($bookings)->toHaveCount(2)
        ->and($bookings->pluck('group_id')->unique())->toHaveCount(1)
        ->and($bookings->first()?->group_id)->not->toBeNull()
        ->and($bookings->first()?->claims)->toHaveCount(4)
        ->and($bookings->last()?->claims)->toHaveCount(4);
});

test('an over-cap agency lands on ON_HOLD_AGENCY with the existing cap task and alert', function (): void {
    $agency = approvedAgency(['name' => 'Meridian', 'commission_pct' => 15]);
    $user = agencyUser([], $agency);
    $hours = app(CurrentConfig::class)->businessRules()->sla->responseHours;

    $response = postPortalRequest($user, portalRequestBody());

    $response->assertCreated()
        ->assertJsonPath('status', BookingStatus::OnHoldAgency->value)
        ->assertJsonPath('message', PortalRequestWords::forStatus(BookingStatus::OnHoldAgency, $hours));

    $booking = Booking::query()->where('request_reference', $response->json('references.0'))->firstOrFail();

    expect($booking->status)->toBe(BookingStatus::OnHoldAgency)
        ->and($booking->commission_pct)->toBe(15)
        ->and($booking->commission_approved)->toBeFalse()
        ->and($booking->claims)->toHaveCount(4);

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

test('a sold-out family stay is refused and writes no request', function (): void {
    $user = agencyUser();
    $property = Property::query()->where('code', 'HTL')->firstOrFail();
    $rooms = Room::query()
        ->where('property_id', $property->id)
        ->whereHas('roomType', fn ($query) => $query->where('code', 'FAM'))
        ->get();
    $holder = ClaimHolder::query()->create(['reference' => 'BLK', 'name' => 'Taken']);
    $before = Booking::query()->count();

    DB::transaction(function () use ($rooms, $holder): void {
        app(ClaimService::class)->claim(StayDates::of('2026-12-21', '2026-12-25'), $rooms, $holder, ClaimKind::Block);
    });

    postPortalRequest($user, portalRequestBody())
        ->assertConflict();

    postPortalRequest($user, portalRequestBody([
        'rooms' => [[
            'room_type' => 'NOPE',
            'adults' => 2,
            'child_ages' => [],
        ]],
    ]))->assertUnprocessable();

    expect(Booking::query()->count())->toBe($before);
});

test('the portal cannot set a price, a discount, a commission, or another agency', function (): void {
    $user = agencyUser();
    $before = Booking::query()->count();

    postPortalRequest($user, portalRequestBody([
        'price' => 1000,
        'discount' => 10,
        'commission_pct' => 5,
        'promo_code' => 'SAVE',
        'agency_id' => 999,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price', 'discount', 'commission_pct', 'promo_code', 'agency_id']);

    postPortalRequest($user, portalRequestBody([
        'client_of_record' => false,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['client_of_record']);

    expect(Booking::query()->count())->toBe($before);
});

test('an agency only sees its own requests', function (): void {
    $owner = agencyUser();
    $other = agencyUser();

    $created = postPortalRequest($owner, portalRequestBody())->assertCreated();

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

    postPortalRequest($user, portalRequestBody())->assertCreated();

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
