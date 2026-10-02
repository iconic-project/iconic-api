<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const CHARTER_RULES_APPROVAL = 'Sprint 12: charter.deposit_business_days added (default 5, source FIN-003); charter.proposal_valid_business_days added (default 10, source O5, PENDING CLIENT); cancellation.charter_bands added (default copies cabin bands, source O6, PENDING CLIENT)';

/**
 * @return array<string, mixed>
 */
function preChangeCharterRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['charter']);
    unset($document['cancellation']['charter_bands']);

    return $document;
}

function runCharterRulesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_23_120005_add_charter_rules_to_business_rules.php');
    $migration->up();
}

function insertPreChangeCharterRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeCharterRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when charter rules are missing, then the migration publishes them', function (): void {
    $v1 = insertPreChangeCharterRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: charter.deposit_business_days');

    runCharterRulesMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('charter');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(CHARTER_RULES_APPROVAL);
    expect($v2->document['charter']['deposit_business_days'])->toBe(5);
    expect($v2->document['charter']['proposal_valid_business_days'])->toBe(10);
    expect($v2->document['cancellation']['charter_bands'])->toHaveCount(3);

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

test('the charter rules migration is a no-op when the keys are already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runCharterRulesMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
});

test('a document without charter rules reads the new fields as empty defaults', function (): void {
    $document = BusinessRulesDocument::fromArray([]);

    expect($document->charter->depositBusinessDays)->toBe(0);
    expect($document->charter->proposalValidBusinessDays)->toBe(0);
    expect($document->charterBands)->toBe([]);
});
