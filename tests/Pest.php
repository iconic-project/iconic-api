<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConfigKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RoomTypeStatus;
use App\Enums\SystemRole;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\ConfigRegistry;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Config\Documents\ExtrasDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\SensitiveFields;
use Illuminate\Testing\TestResponse;
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
 * Read one path from docs/requirements/examples/hotel-seed-data.json.
 */
function hotelFixture(string $path): mixed
{
    /** @var array<string, mixed>|null $fixture */
    static $fixture = null;

    if ($fixture === null) {
        $file = base_path('docs/requirements/examples/hotel-seed-data.json');
        $decoded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $fixture = is_array($decoded) ? $decoded : [];
    }

    return data_get($fixture, $path);
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
    unset($overrides['departure'], $overrides['cabin_code'], $overrides['actor']);
    $paid = $overrides['paid'] ?? null;
    unset($overrides['paid'], $overrides['departure_id']);

    $booking = Booking::factory()->create($overrides);

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
    unset($overrides['departure'], $overrides['departure_id']);

    return Booking::factory()->create([
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
    unset($overrides['departure'], $overrides['cabin_code'], $overrides['departure_id']);
    $skipDeposit = (bool) ($overrides['skip_deposit'] ?? false);
    unset($overrides['skip_deposit']);

    $booking = Booking::factory()->create([
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

function publishStayRates(): void
{
    $property = Property::factory()->create();
    $types = [
        ['STD', 'Standard Double', 2, 2, 2, 0],
        ['TWN', 'Twin', 2, 2, 2, 0],
        ['FAM', 'Family', 2, 4, 4, 2],
        ['STE', 'Suite', 2, 3, 3, 1],
        ['LIM', 'Limit', 2, 3, 2, 2],
    ];

    foreach ($types as $index => [$code, $name, $base, $max, $adults, $children]) {
        RoomType::query()->create([
            'property_id' => $property->id,
            'code' => $code,
            'name' => $name,
            'slug' => strtolower($code).'-stay',
            'base_occupancy' => $base,
            'max_occupancy' => $max,
            'max_adults' => $adults,
            'max_children' => $children,
            'sort' => $index + 1,
            'status' => RoomTypeStatus::Active,
        ]);
    }

    $config = app(CurrentConfig::class);

    app(ConfigPublisher::class)->publish(
        ConfigKind::Rates,
        RatesDocument::initial(),
        $config->version(ConfigKind::Rates)->version,
        'STAY-RATES',
        adminUser(),
    );
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

/**
 * @param  array<mixed>  $value
 * @return list<string>
 */
function engineKeys(array $value): array
{
    $keys = [];

    $walk = function (mixed $node) use (&$walk, &$keys): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            $walk($child);
        }
    };

    $walk($value);

    return $keys;
}
