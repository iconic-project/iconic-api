<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const CONSENT_VERSIONS_APPROVAL = 'Sprint 6: legal.consent_versions added (defaults from prototype CONSENT_VER, sources LEG-001 / LEG-002 / OPS-005)';

/**
 * @return array<string, mixed>
 */
function preChangeConsentVersions(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['legal']);

    return $document;
}

function runConsentVersionsMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_21_200035_add_consent_versions_to_business_rules.php');
    $migration->up();
}

function insertPreChangeConsentVersions(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeConsentVersions(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing consent versions, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeConsentVersions();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: legal.consent_versions.terms')
        ->expectsOutputToContain('business_rules v1: legal.consent_versions.marketing');

    runConsentVersionsMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('legal');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(CONSENT_VERSIONS_APPROVAL);
    expect($v2->document['legal']['consent_versions']['terms'])->toBe('v2026.1 (text pending LEG-001)');
    expect($v2->document['legal']['consent_versions']['cancellation'])->toBe('v2026.1 (pending LEG-001)');
    expect($v2->document['legal']['consent_versions']['privacy'])->toBe('v2026.1 (pending LEG-002)');
    expect($v2->document['legal']['consent_versions']['insurance'])->toBe('OPS-005 v1');
    expect($v2->document['legal']['consent_versions']['marketing'])->toBe('v1');
    expect($v2->document['legal']['consent_versions']['analytics'])->toBe('v1 (pending LEG-002)');
    expect($v2->document['retention']['passport_months_after_check_out'])->toBe(24);

    $history = ChangeHistory::query()
        ->where('event', 'business_rules.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_id)->toBeNull();
    expect($history?->actor_label)->toBe('System');
    expect($history?->reason)->toBe(CONSENT_VERSIONS_APPROVAL);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v2: valid');
});

test('the migration adds only the missing consent-version keys', function (): void {
    $document = preChangeConsentVersions();
    $document['legal'] = [
        'consent_versions' => [
            'terms' => 'custom-terms',
        ],
    ];

    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE-PARTIAL',
        'published_at' => now(),
    ]);
    $row->save();

    runConsentVersionsMigration();

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->document['legal']['consent_versions']['terms'])->toBe('custom-terms');
    expect($v2->document['legal']['consent_versions']['privacy'])->toBe('v2026.1 (pending LEG-002)');
    expect($v2->document['legal']['consent_versions']['marketing'])->toBe('v1');
    expect($v2->document['legal']['consent_versions']['analytics'])->toBe('v1 (pending LEG-002)');
});

test('the migration is a no-op when the five consent versions are already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runConsentVersionsMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('the migration is a no-op when no business-rules version exists', function (): void {
    runConsentVersionsMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(0);
});
