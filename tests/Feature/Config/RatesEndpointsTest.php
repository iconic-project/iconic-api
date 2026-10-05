<?php

declare(strict_types=1);

use App\Models\ChangeHistory;
use App\Models\RateVersion;
use App\Support\Config\Documents\RatesDocument;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a sales exec can view rates and cannot publish', function (): void {
    $sales = salesExecUser();

    $this->actingAs($sales)
        ->getJson('/api/rms/rates')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('document.years.0.suite_pp', 13300)
        ->assertJsonPath('published_by', null);

    $document = ratesDocument();
    $document['years'][1]['suite_pp'] = 15000;

    $this->actingAs($sales)
        ->postJson('/api/rms/rates/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-1',
        ])
        ->assertForbidden();

    expect(RateVersion::query()->count())->toBe(1);
});

test('an admin can publish an occupancy change', function (): void {
    $admin = adminUser(['name' => 'Carolina M.']);
    $document = ratesDocument();
    $from = (int) $document['occupancy']['extra_adult_nightly'];
    $document['occupancy']['extra_adult_nightly'] = $from + 1;

    $this->actingAs($admin)
        ->postJson('/api/rms/rates/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-22',
        ])
        ->assertCreated()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('changes.0.label', 'Extra adult / night')
        ->assertJsonPath('changes.0.from', $from)
        ->assertJsonPath('changes.0.to', $from + 1)
        ->assertJsonPath('approval_reference', 'BOARD-22');

    $entry = ChangeHistory::query()->where('event', 'rates.published')->latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->reason)->toBe('BOARD-22');
    expect($entry?->subject_type)->toBe('rate_version');
});

test('price check with an invalid document is a 422 keyed document.path', function (): void {
    $admin = adminUser();
    $document = ratesDocument(['currency' => 'EUR']);

    $this->actingAs($admin)
        ->postJson('/api/rms/rates/price-check', [
            'year' => 2027,
            'document' => $document,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document.currency']);
});

test('price check defaults to the fixture reference quotes and does not publish', function (): void {
    $admin = adminUser();
    $versions = RateVersion::query()->count();

    $response = $this->actingAs($admin)
        ->postJson('/api/rms/rates/price-check', [
            'document' => RatesDocument::initial(),
        ]);

    $response->assertOk();
    expect($response->json('scenarios'))->toHaveCount(8);
    expect($response->json('scenarios.0.key'))->toBe('Q1');
    expect($response->json('scenarios.7.key'))->toBe('Q8');
    expect(RateVersion::query()->count())->toBe($versions);
});

test('get current rates is version 1 after the seeder', function (): void {
    $this->actingAs(adminUser())
        ->getJson('/api/rms/rates')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('document.currency', 'USD')
        ->assertJsonPath('document.years.0.year', 2027)
        ->assertJsonPath('approval_reference', ConfigSeeder::APPROVAL_REFERENCE);
});
