<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\EngineSettingsVersion;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const CHARTER_KEYS_REMOVED = 'Sprint 22: charter keys removed (exclusive use dropped, HQ1)';

function runRemoveCharterConfigKeys(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_170001_remove_charter_config_keys.php');
    $migration->up();
}

test('the removal migration is a no-op when the charter groups are already gone', function (): void {
    $this->seed(ConfigSeeder::class);

    runRemoveCharterConfigKeys();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(EngineSettingsVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->firstOrFail()->document)->not->toHaveKey('charter');
    expect(EngineSettingsVersion::query()->firstOrFail()->document)->not->toHaveKey('charter');
});

test('the removal migration publishes versions without the charter groups', function (): void {
    $this->seed(ConfigSeeder::class);

    $rules = BusinessRuleVersion::query()->firstOrFail();
    $rulesDocument = $rules->document;
    $rulesDocument['charter'] = [
        'deposit_business_days' => 5,
        'proposal_valid_business_days' => 10,
    ];
    (new BusinessRuleVersion([
        'version' => 2,
        'document' => $rulesDocument,
        'changes' => [],
        'approval_reference' => 'PRE-REMOVAL',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    $settings = EngineSettingsVersion::query()->firstOrFail();
    $settingsDocument = $settings->document;
    $settingsDocument['charter'] = [
        'headline' => 'The property, entirely yours',
        'intro' => 'A week for your group.',
        'itinerary_label' => 'Customizable',
        'response_sla_hours' => 24,
        'group_contexts' => ['Family'],
        'thank_you' => 'Thank you.',
    ];
    (new EngineSettingsVersion([
        'version' => 2,
        'document' => $settingsDocument,
        'changes' => [],
        'approval_reference' => 'PRE-REMOVAL',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    runRemoveCharterConfigKeys();

    $rulesLatest = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    $settingsLatest = EngineSettingsVersion::query()->orderByDesc('version')->firstOrFail();

    expect($rulesLatest->version)->toBe(3);
    expect($rulesLatest->approval_reference)->toBe(CHARTER_KEYS_REMOVED);
    expect($rulesLatest->document)->not->toHaveKey('charter');
    expect($rulesLatest->document['cancellation']['charter_bands'])->not->toBeEmpty();
    expect($settingsLatest->version)->toBe(3);
    expect($settingsLatest->approval_reference)->toBe(CHARTER_KEYS_REMOVED);
    expect($settingsLatest->document)->not->toHaveKey('charter');

    $this->artisan('iconic:config-verify')->assertSuccessful();
});
