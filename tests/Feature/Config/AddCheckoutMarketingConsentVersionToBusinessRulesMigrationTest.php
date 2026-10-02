<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const CHECKOUT_MARKETING_CONSENT_APPROVAL = 'Sprint 14: legal.consent_versions.checkout_marketing added (default v1 (pending LEG-002), source LEG-002)';

/**
 * @return array<string, mixed>
 */
function preChangeCheckoutMarketingConsent(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['legal']['consent_versions']['checkout_marketing']);

    return $document;
}

function runCheckoutMarketingConsentMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_23_190001_add_checkout_marketing_consent_version_to_business_rules.php');
    $migration->up();
}

function insertPreChangeCheckoutMarketingConsent(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeCheckoutMarketingConsent(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when checkout marketing consent is missing, then the migration publishes it', function (): void {
    $v1 = insertPreChangeCheckoutMarketingConsent();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: legal.consent_versions.checkout_marketing');

    runCheckoutMarketingConsentMigration();

    $v1->refresh();
    expect($v1->document['legal']['consent_versions'])->not->toHaveKey('checkout_marketing');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(CHECKOUT_MARKETING_CONSENT_APPROVAL);
    expect($v2->document['legal']['consent_versions']['checkout_marketing'])->toBe('v1 (pending LEG-002)');

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
