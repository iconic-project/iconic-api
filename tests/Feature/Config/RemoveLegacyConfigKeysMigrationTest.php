<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\EngineSettingsVersion;
use App\Models\RateVersion;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const LEGACY_KEYS_REMOVED = 'Sprint 22: legacy keys removed (09 H19)';

function runRemoveLegacyConfigKeys(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_190001_remove_legacy_config_keys.php');
    $migration->up();
}

test('the legacy-key migration is a no-op when those keys are already gone', function (): void {
    $this->seed(ConfigSeeder::class);

    runRemoveLegacyConfigKeys();

    expect(RateVersion::query()->count())->toBe(1);
    expect(EngineSettingsVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(RateVersion::query()->firstOrFail()->document)->not->toHaveKey('years');
    expect(EngineSettingsVersion::query()->firstOrFail()->document['fees'] ?? [])->not->toHaveKey('png');
    expect(BusinessRuleVersion::query()->firstOrFail()->document)->not->toHaveKey('manifests');
});

test('the legacy-key migration publishes one version per document that still has a key', function (): void {
    $this->seed(ConfigSeeder::class);

    $rates = RateVersion::query()->firstOrFail();
    $ratesDocument = $rates->document;
    $ratesDocument['years'] = [2027];
    $ratesDocument['rules'] = ['single_supplement_pct' => 15];
    $ratesDocument['terms'] = [
        'cabin_deposit_pct' => 30,
        'charter_deposit_pct' => 50,
    ];
    (new RateVersion([
        'version' => 2,
        'document' => $ratesDocument,
        'changes' => [],
        'approval_reference' => 'PRE-REMOVAL',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    $settings = EngineSettingsVersion::query()->firstOrFail();
    $settingsDocument = $settings->document;
    $settingsDocument['fees']['png'] = ['label' => 'Entry'];
    $settingsDocument['fees']['tct_pp'] = 20;
    $settingsDocument['guests']['max_per_cabin'] = 3;
    $settingsDocument['guests']['max_per_yacht'] = 16;
    (new EngineSettingsVersion([
        'version' => 2,
        'document' => $settingsDocument,
        'changes' => [],
        'approval_reference' => 'PRE-REMOVAL',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    $rules = BusinessRuleVersion::query()->firstOrFail();
    $rulesDocument = $rules->document;
    $rulesDocument['manifests'] = ['due_days' => 7];
    $rulesDocument['charter'] = ['deposit_business_days' => 5];
    (new BusinessRuleVersion([
        'version' => 2,
        'document' => $rulesDocument,
        'changes' => [],
        'approval_reference' => 'PRE-REMOVAL',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    runRemoveLegacyConfigKeys();

    $ratesLatest = RateVersion::query()->orderByDesc('version')->firstOrFail();
    $settingsLatest = EngineSettingsVersion::query()->orderByDesc('version')->firstOrFail();
    $rulesLatest = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();

    expect($ratesLatest->version)->toBe(3);
    expect($ratesLatest->approval_reference)->toBe(LEGACY_KEYS_REMOVED);
    expect($ratesLatest->document)->not->toHaveKey('years');
    expect($ratesLatest->document)->not->toHaveKey('rules');
    expect($ratesLatest->document)->not->toHaveKey('terms');
    expect($ratesLatest->document)->toHaveKey('room_rates');

    expect($settingsLatest->version)->toBe(3);
    expect($settingsLatest->approval_reference)->toBe(LEGACY_KEYS_REMOVED);
    expect($settingsLatest->document['fees'])->not->toHaveKey('png');
    expect($settingsLatest->document['fees'])->not->toHaveKey('tct_pp');
    expect($settingsLatest->document['guests'])->not->toHaveKey('max_per_cabin');
    expect($settingsLatest->document['guests'])->not->toHaveKey('max_per_yacht');
    expect($settingsLatest->document['guests'])->toHaveKey('max_per_property');

    expect($rulesLatest->version)->toBe(3);
    expect($rulesLatest->approval_reference)->toBe(LEGACY_KEYS_REMOVED);
    expect($rulesLatest->document)->not->toHaveKey('manifests');
    expect($rulesLatest->document)->not->toHaveKey('charter');
    expect($rulesLatest->document['cancellation']['charter_bands'])->not->toBeEmpty();

    $this->artisan('iconic:config-verify')->assertSuccessful();
});
