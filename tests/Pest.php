<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConfigKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Config\ConfigRegistry;
use App\Services\Inventory\ClaimService;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Config\Documents\ExtrasDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\SensitiveFields;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Config\TestConfigDocument;
use Tests\Support\Config\TestConfigVersion;
use Tests\TestCase;
use Tests\TruncatingTestCase;

/*
| Feature tests boot the Laravel application and refresh the database.
| Unit tests stay on PHPUnit\Framework\TestCase and do not touch the database.
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TruncatingTestCase::class)->in('Concurrency');

afterEach(function (): void {
    ClaimService::$beforeConvert = null;
});

function assertNoSensitiveFields(TestResponse $response): void
{
    $json = $response->json();

    expect(SensitiveFields::keysIn(is_array($json) ? $json : []))->toBeEmpty();
}

/**
 * @return array<string, string>
 */
function panelHeaders(): array
{
    return [
        'Origin' => 'http://localhost:3001',
        'Referer' => 'http://localhost:3001/login',
        'Accept' => 'application/json',
    ];
}

function withPanelCsrf(): TestCase
{
    $test = test();
    $test->withHeaders(panelHeaders())->get('/sanctum/csrf-cookie');

    return $test->withHeaders([
        ...panelHeaders(),
        'X-CSRF-TOKEN' => csrf_token() ?: '',
    ]);
}

/**
 * @return array<string, string>
 */
function portalHeaders(): array
{
    return [
        'Origin' => 'http://localhost:3002',
        'Referer' => 'http://localhost:3002/login',
        'Accept' => 'application/json',
    ];
}

function withPortalCsrf(): TestCase
{
    $test = test();
    $test->withHeaders(portalHeaders())->get('/sanctum/csrf-cookie');

    return $test->withHeaders([
        ...portalHeaders(),
        'X-CSRF-TOKEN' => csrf_token() ?: '',
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function approvedAgency(array $attributes = []): Agency
{
    return Agency::factory()->create($attributes);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function agencyUser(array $attributes = [], ?Agency $agency = null): AgencyUser
{
    return AgencyUser::factory()->active()->for($agency ?? approvedAgency(), 'agency')->create($attributes);
}

function adminUser(array $attributes = []): User
{
    return User::factory()->withRole(SystemRole::Admin)->create($attributes);
}

function managerUser(array $attributes = []): User
{
    return User::factory()->withRole(SystemRole::Manager)->create($attributes);
}

function salesExecUser(array $attributes = []): User
{
    return User::factory()->withRole(SystemRole::SalesExec)->create($attributes);
}

function externalFinanceUser(array $attributes = []): User
{
    $role = Role::factory()->create([
        'permissions' => [
            Permission::PanelRms,
            Permission::BookingsViewAll,
            Permission::PaymentsRecord,
            Permission::PaymentsMarkWireReceived,
            Permission::RefundsExecute,
            Permission::CommissionsRecordPayout,
        ],
    ]);

    return User::factory()->create([
        'role_id' => $role->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>|null  $initial
 */
function registerTestConfig(?array $initial = null): void
{
    app(ConfigRegistry::class)->register(
        ConfigKind::Rates,
        TestConfigVersion::class,
        TestConfigDocument::class,
        $initial,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{terms: array{cabin_deposit_pct: int}, title: string, bands: list<array{min: int, pct: int}>}
 */
function testConfigDocument(array $overrides = []): array
{
    /** @var array{terms: array{cabin_deposit_pct: int}, title: string, bands: list<array{min: int, pct: int}>} $document */
    $document = array_replace_recursive([
        'terms' => [
            'cabin_deposit_pct' => 10,
        ],
        'title' => 'Cabin terms',
        'bands' => [
            ['min' => 120, 'pct' => 5],
        ],
    ], $overrides);

    return $document;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ratesDocument(array $overrides = []): array
{
    /** @var array<string, mixed> $document */
    $document = array_replace_recursive(RatesDocument::initial(), $overrides);

    return $document;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function engineSettingsDocument(array $overrides = []): array
{
    /** @var array<string, mixed> $document */
    $document = array_replace_recursive(EngineSettingsDocument::initial(), $overrides);

    return $document;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function businessRulesDocument(array $overrides = []): array
{
    /** @var array<string, mixed> $document */
    $document = array_replace_recursive(BusinessRulesDocument::initial(), $overrides);

    return $document;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extrasDocument(array $overrides = []): array
{
    /** @var array<string, mixed> $document */
    $document = array_replace_recursive(ExtrasDocument::initial(), $overrides);

    return $document;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function refundCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture();
    unset($overrides['departure']);
    $actor = $overrides['actor'] ?? adminUser();
    unset($overrides['actor']);
    $cabin = $overrides['cabin_code'] ?? 'S1';
    unset($overrides['cabin_code']);
    $paid = $overrides['paid'] ?? null;
    unset($overrides['paid']);

    $id = test()->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => $cabin, 'adults' => 2, 'children' => 0]],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $booking = Booking::query()->findOrFail($id);

    if ($overrides !== []) {
        $booking->update($overrides);
        $booking->refresh();
    }

    if (is_int($paid) && $paid > 0) {
        Payment::factory()->create([
            'booking_id' => $booking->id,
            'kind' => PaymentKind::Deposit,
            'amount' => $paid,
            'status' => PaymentStatus::Settled,
            'reference' => $booking->displayReference().'-D01',
        ]);
    }

    return $booking->fresh() ?? $booking;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pendingCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture();
    unset($overrides['departure']);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::PendingPayment,
        'total' => 26600,
        'deposit_pct' => 10,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function overdueCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture();
    unset($overrides['departure']);
    $cabin = $overrides['cabin_code'] ?? 'S1';
    unset($overrides['cabin_code']);
    $skipDeposit = (bool) ($overrides['skip_deposit'] ?? false);
    unset($overrides['skip_deposit']);

    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'deposit_pct' => 10,
        'balance_days' => 120,
        'balance_due_date_override' => BusinessTime::now()->subDay()->toDateString(),
        ...$overrides,
    ]);

    if (! $skipDeposit) {
        Payment::factory()->create([
            'booking_id' => $booking->id,
            'kind' => PaymentKind::Deposit,
            'status' => PaymentStatus::Settled,
            'amount' => $booking->depositAmount(),
            'reference' => $booking->displayReference().'-D01',
        ]);
    }

    return $booking->fresh() ?? $booking;
}

function limitedAdminRole(): Role
{
    return Role::factory()->create([
        'permissions' => [
            Permission::PanelRms,
            Permission::UsersManage,
            Permission::RolesManage,
        ],
    ]);
}

/**
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function rmsManagementRequests(User $target, Role $role): array
{
    return [
        ['getJson', '/api/rms/permissions', []],
        ['getJson', '/api/rms/roles', []],
        ['postJson', '/api/rms/roles', ['name' => 'Temp role '.uniqid(), 'permissions' => []]],
        ['patchJson', "/api/rms/roles/{$role->id}", ['description' => 'x']],
        ['deleteJson', "/api/rms/roles/{$role->id}", []],
        ['getJson', "/api/rms/roles/{$role->id}/history", []],
        ['getJson', '/api/rms/users', []],
        ['postJson', '/api/rms/users', [
            'name' => 'Invitee',
            'email' => 'invitee-'.uniqid().'@iconic.test',
            'role_id' => $role->id,
        ]],
        ['patchJson', "/api/rms/users/{$target->id}", ['name' => 'Renamed']],
        ['postJson', "/api/rms/users/{$target->id}/disable", []],
        ['postJson', "/api/rms/users/{$target->id}/enable", []],
        ['postJson', "/api/rms/users/{$target->id}/resend-invitation", []],
        ['getJson', "/api/rms/users/{$target->id}/history", []],
    ];
}
