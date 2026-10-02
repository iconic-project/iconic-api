<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const REPORTS_RETENTION_APPROVAL = 'Sprint 12: reports.retention_days added (default 90, source O2, PENDING CLIENT)';

/**
 * @return array<string, mixed>
 */
function preChangeReportsRetention(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['reports']);

    return $document;
}

function runReportsRetentionMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_23_120001_add_reports_retention_to_business_rules.php');
    $migration->up();
}

function insertPreChangeReportsRetention(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeReportsRetention(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when report retention is missing, then the migration publishes it', function (): void {
    $v1 = insertPreChangeReportsRetention();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: reports.retention_days');

    runReportsRetentionMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('reports');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(REPORTS_RETENTION_APPROVAL);
    expect($v2->document['reports']['retention_days'])->toBe(90);

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

test('the reports retention migration is a no-op when the key is already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runReportsRetentionMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('fromArray defaults a missing reports retention key', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['reports']);

    expect(BusinessRulesDocument::fromArray($missing)->reports->retentionDays)->toBe(0);
});
