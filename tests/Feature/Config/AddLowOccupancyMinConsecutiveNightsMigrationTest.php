<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const MIN_NIGHTS_APPROVAL = 'Sprint 21: low_occupancy_min_consecutive_nights added (default 1, source 09 H15)';

function runMinNightsMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_140001_add_low_occupancy_min_consecutive_nights.php');
    $migration->up();
}

test('the migration adds the consecutive-night minimum and does nothing when it is present', function (): void {
    $document = BusinessRulesDocument::initial();
    unset($document['alerts']['low_occupancy_min_consecutive_nights']);

    $v1 = new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $v1->save();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: alerts.low_occupancy_min_consecutive_nights');

    runMinNightsMigration();

    $v1->refresh();
    expect($v1->document['alerts'])->not->toHaveKey('low_occupancy_min_consecutive_nights');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2)
        ->and($v2->approval_reference)->toBe(MIN_NIGHTS_APPROVAL)
        ->and($v2->document['alerts']['low_occupancy_min_consecutive_nights'])->toBe(1)
        ->and($v2->document['alerts']['low_occupancy_pct'])->toBe(40);

    runMinNightsMigration();
    expect(BusinessRuleVersion::query()->count())->toBe(2);

    $this->artisan('iconic:config-verify')->assertSuccessful();
});
