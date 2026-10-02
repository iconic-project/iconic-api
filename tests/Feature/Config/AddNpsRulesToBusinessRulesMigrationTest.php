<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const NPS_RULES_APPROVAL = 'Sprint 11: nps.* added (defaults 24 / 7 / 8 / PENDING CLIENT, source N8, review URL PENDING CLIENT)';

/**
 * @return array<string, mixed>
 */
function preChangeNpsRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['nps']);

    return $document;
}

function runNpsRulesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_22_240001_add_nps_rules_to_business_rules.php');
    $migration->up();
}

function insertPreChangeNpsRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeNpsRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when the nps rules are missing, then the migration publishes them', function (): void {
    $v1 = insertPreChangeNpsRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: nps');

    runNpsRulesMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('nps');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(NPS_RULES_APPROVAL);
    expect($v2->document['nps']['survey_hours_after_return'])->toBe(24);
    expect($v2->document['nps']['alert_below'])->toBe(7);
    expect($v2->document['nps']['review_request_from'])->toBe(8);
    expect($v2->document['nps']['review_url'])->toBe('PENDING CLIENT');

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
