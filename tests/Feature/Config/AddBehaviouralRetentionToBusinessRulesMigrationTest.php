<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const BEHAVIOURAL_RETENTION_APPROVAL = 'Sprint 9: retention.behavioural_raw_months (24) and retention.behavioural_unstitched_days (30) added (PENDING CLIENT, source L6 / doc 07 §8, LEG-002)';

/**
 * @return array<string, mixed>
 */
function preChangeBehaviouralRetention(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['retention']['behavioural_raw_months'], $document['retention']['behavioural_unstitched_days']);

    return $document;
}

function runBehaviouralRetentionMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_21_200081_add_behavioural_event_retention_to_business_rules.php');
    $migration->up();
}

function insertPreChangeBehaviouralRetention(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeBehaviouralRetention(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing behavioural retention, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeBehaviouralRetention();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: retention.behavioural_raw_months')
        ->expectsOutputToContain('business_rules v1: retention.behavioural_unstitched_days');

    runBehaviouralRetentionMigration();

    $v1->refresh();
    expect($v1->document['retention'])->not->toHaveKey('behavioural_raw_months');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(BEHAVIOURAL_RETENTION_APPROVAL);
    expect($v2->document['retention']['behavioural_raw_months'])->toBe(24);
    expect($v2->document['retention']['behavioural_unstitched_days'])->toBe(30);

    $history = ChangeHistory::query()
        ->where('event', 'business_rules.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_label)->toBe('System');

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v2: valid');
});

test('the behavioural retention migration is a no-op when the keys are already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runBehaviouralRetentionMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('fromArray defaults missing behavioural retention keys', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['retention']['behavioural_raw_months'], $missing['retention']['behavioural_unstitched_days']);

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->retention->behaviouralRawMonths)->toBe(0);
    expect($lenient->retention->behaviouralUnstitchedDays)->toBe(0);
});
