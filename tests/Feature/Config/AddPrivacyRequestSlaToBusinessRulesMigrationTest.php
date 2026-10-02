<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const PRIVACY_SLA_APPROVAL = 'Sprint 10: privacy.request_sla_days added (default 30, source M7, PENDING LEG-002)';

/**
 * @return array<string, mixed>
 */
function preChangePrivacyRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['privacy']);

    return $document;
}

function runPrivacySlaMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_22_200008_add_privacy_request_sla_to_business_rules.php');
    $migration->up();
}

function insertPreChangePrivacyRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangePrivacyRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when the subject-request sla is missing, then the migration publishes it', function (): void {
    $v1 = insertPreChangePrivacyRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: privacy');

    runPrivacySlaMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('privacy');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(PRIVACY_SLA_APPROVAL);
    expect($v2->document['privacy']['request_sla_days'])->toBe(30);

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
