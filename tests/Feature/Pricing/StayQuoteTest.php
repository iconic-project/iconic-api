<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('a stay quote returns the room total and the plan deposit', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-04',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('check_in', '2026-02-02')
        ->assertJsonPath('check_out', '2026-02-04')
        ->assertJsonPath('rooms.0.room_type', 'STD')
        ->assertJsonPath('rooms.0.quote.total', 200)
        ->assertJsonPath('rooms.0.quote.deposit', 60)
        ->assertJsonPath('rooms.0.errors', [])
        ->assertJsonPath('total', 200)
        ->assertJsonPath('deposit', 60);
});

test('a multi-room stay sums the room totals', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-03',
            'rooms' => [
                ['room_type' => 'STD', 'adults' => 2, 'child_ages' => [], 'rate_plan' => 'BAR'],
                ['room_type' => 'FAM', 'adults' => 3, 'child_ages' => [], 'rate_plan' => 'BAR'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('rooms.0.quote.total', 100)
        ->assertJsonPath('rooms.1.quote.total', 200)
        ->assertJsonPath('total', 300)
        ->assertJsonPath('deposit', 90);
});

test('a stay quote refuses a check-out that is not after check-in', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-02',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_out']);
});

test('a stay quote requires a room', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-04',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rooms']);
});

test('a user without bookings.create cannot quote a stay', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-04',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
            ]],
        ])
        ->assertForbidden();
});

test('guest limits come back on the room', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-03',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [10],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('total', null)
        ->assertJsonPath('rooms.0.errors.0', 'This room takes at most 0 children.');
});

test('the online deposit discount is capped at max_total_discount_pct', function (): void {
    $config = app(CurrentConfig::class);
    $rules = $config->businessRules()->toArray();
    $rules['discounts']['online_deposit_discount_pct'] = 50;
    $rules['discounts']['max_total_discount_pct'] = 10;

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $rules,
        $config->version(ConfigKind::BusinessRules)->version,
        'CAP-1',
        adminUser(),
    );

    $response = $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-04',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
                'online_deposit' => true,
            ]],
        ]);

    $response->assertOk()
        ->assertJsonPath('total', 180)
        ->assertJsonPath('rooms.0.quote.deposit', 54);

    $lines = $response->json('rooms.0.quote.lines');
    $discount = collect($lines)->firstWhere('code', 'online_deposit');
    expect($discount['amount'])->toBe(-20);
    expect($discount['label'])->toContain('reduced to the maximum discount');
});

test('the guest validation matrix names each limit', function (int $adults, array $ages, string $error): void {
    $type = RoomType::query()->where('code', 'LIM')->first();
    expect($type)->not->toBeNull();

    $result = app(StayQuoter::class)->quote($type, new StayQuoteInput(
        StayDates::forNights('2026-02-02', 1),
        'LIM',
        $adults,
        $ages,
        'BAR',
    ));

    expect($result)->toBeInstanceOf(GuestsInvalid::class);
    expect($result->errors)->toContain($error);
})->with([
    'adults' => [3, [], 'This room takes at most 2 adults.'],
    'children' => [1, [10, 11, 12], 'This room takes at most 2 children.'],
    'occupancy' => [2, [10, 11], 'This room takes at most 3 guests.'],
    'under age' => [1, [5], 'Under 6 not accommodated'],
    'over age' => [1, [18], 'A child aged 18 is over the maximum age of 17.'],
    'no adult' => [0, [], 'At least 1 adult is required.'],
]);
