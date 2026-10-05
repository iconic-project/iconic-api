<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\RateVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Config\Documents\RatesDocument;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('price check reproduces every reference quote total', function (): void {
    $versions = RateVersion::query()->count();
    $fixture = json_decode(
        (string) file_get_contents(base_path('docs/requirements/examples/hotel-seed-data.json')),
        true,
    );

    $response = $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
        ]);

    $response->assertOk();
    expect(RateVersion::query()->count())->toBe($versions);

    foreach ($fixture['reference_quotes'] as $quote) {
        $scenario = collect($response->json('scenarios'))->firstWhere('key', $quote['id']);
        expect($scenario)->not->toBeNull();
        expect($scenario['draft']['total'])->toBe($quote['expected_total']);
        expect($scenario['published']['total'])->toBe($quote['expected_total']);
        expect($scenario['difference'])->toBe(0);
        expect($scenario['input']['room_type'])->toBe($quote['input']['room_type']);
        expect($scenario['input']['check_in'])->toBe($quote['input']['check_in']);
        expect($scenario['input']['nights'])->toBe($quote['input']['nights']);
        expect($scenario['input']['adults'])->toBe($quote['input']['adults']);
        expect($scenario['input']['rate_plan'])->toBe($quote['input']['rate_plan']);
    }
});

test('an explicit stay list is priced and an invalid document is refused', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
            'stays' => [[
                'id' => 'ONE',
                'room_type' => 'STD',
                'check_in' => '2026-02-02',
                'nights' => 2,
                'adults' => 2,
                'rate_plan' => 'BAR',
            ]],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'scenarios')
        ->assertJsonPath('scenarios.0.key', 'ONE')
        ->assertJsonPath('scenarios.0.draft.total', 200);

    $document = RatesDocument::initial();
    $document['currency'] = 'EUR';

    $this->actingAs(managerUser())
        ->postJson('/api/rms/rates/price-check', [
            'document' => $document,
            'stays' => [[
                'room_type' => 'STD',
                'check_in' => '2026-02-02',
                'nights' => 1,
                'adults' => 2,
                'rate_plan' => 'BAR',
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document.currency']);
});

test('a stay with no rate plan is a validation error', function (): void {
    $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
            'stays' => [[
                'room_type' => 'STD',
                'check_in' => '2026-02-02',
                'nights' => 1,
                'adults' => 2,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stays.0.rate_plan']);
});

test('price check follows the rates view permission', function (): void {
    $versions = RateVersion::query()->count();

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
        ])
        ->assertOk();

    expect(RateVersion::query()->count())->toBe($versions);

    $role = Role::factory()->create([
        'permissions' => [Permission::BookingsCreate],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
        ])
        ->assertForbidden();
});
