<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const PIPELINE_RULES_APPROVAL = 'Sprint 10: crm.pipeline SLAs and probabilities added (defaults 4h / 5d / 7d and 5 / 15 / 35 / 55 / 80, source M4, PENDING CLIENT)';

/**
 * @return array<string, mixed>
 */
function preChangePipelineRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['crm']['pipeline']);

    return $document;
}

function runPipelineRulesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_22_200006_add_pipeline_rules_to_business_rules.php');
    $migration->up();
}

function insertPreChangePipelineRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangePipelineRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when pipeline rules are missing, then the migration publishes them', function (): void {
    $v1 = insertPreChangePipelineRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: crm.pipeline');

    runPipelineRulesMigration();

    $v1->refresh();
    expect($v1->document['crm'])->not->toHaveKey('pipeline');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(PIPELINE_RULES_APPROVAL);
    expect($v2->document['crm']['pipeline']['probability_quoted'])->toBe(35);
    expect($v2->document['crm']['pipeline']['sla_new_lead_business_hours'])->toBe(4);

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
