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

test('the historical migration still publishes charter bands and does not keep the enquiry group', function (): void {
    $v1 = insertPreChangeCharterRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: cancellation.charter_bands');

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

test('re-running the historical migration on the current document publishes the enquiry group again', function (): void {
    $this->seed(ConfigSeeder::class);

    runCharterRulesMigration();

    $latest = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($latest->version)->toBe(2);
    expect($latest->document['charter']['deposit_business_days'])->toBe(5);
    expect($latest->document['cancellation']['charter_bands'])->not->toBeEmpty();
});

test('a document without charter bands reads them as an empty list', function (): void {
    $document = BusinessRulesDocument::fromArray([]);

    expect($document->charter)->toBeNull();
    expect($document->toArray())->not->toHaveKey('charter');
    expect($document->charterBands)->toBe([]);
});
