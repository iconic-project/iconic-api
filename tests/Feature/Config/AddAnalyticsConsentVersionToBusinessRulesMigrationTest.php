<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const ANALYTICS_CONSENT_APPROVAL = 'Sprint 10: legal.consent_versions.analytics added (default v1 (pending LEG-002), source LEG-002)';

/**
 * @return array<string, mixed>
 */
function preChangeAnalyticsConsent(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['legal']['consent_versions']['analytics']);

    return $document;
}

function runAnalyticsConsentMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_22_200002_add_analytics_consent_version_to_business_rules.php');
    $migration->up();
}

function insertPreChangeAnalyticsConsent(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeAnalyticsConsent(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when analytics consent is missing, then the migration publishes it', function (): void {
    $v1 = insertPreChangeAnalyticsConsent();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: legal.consent_versions.analytics');

    runAnalyticsConsentMigration();

    $v1->refresh();
    expect($v1->document['legal']['consent_versions'])->not->toHaveKey('analytics');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(ANALYTICS_CONSENT_APPROVAL);
    expect($v2->document['legal']['consent_versions']['analytics'])->toBe('v1 (pending LEG-002)');

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
