<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const PORTAL_RULES_APPROVAL = 'Sprint 13: portal.invite_valid_days added (default 14, source Sprint 13 task 01, PENDING CLIENT)';

/**
 * @return array<string, mixed>
 */
function preChangePortalRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['portal']);

    return $document;
}

function runPortalRulesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_23_130004_add_portal_rules_to_business_rules.php');
    $migration->up();
}

function insertPreChangePortalRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangePortalRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when portal rules are missing, then the migration publishes them', function (): void {
    $v1 = insertPreChangePortalRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: portal.invite_valid_days');

    runPortalRulesMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('portal');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(PORTAL_RULES_APPROVAL);
    expect($v2->document['portal']['invite_valid_days'])->toBe(14);

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

test('the portal rules migration is a no-op when the key is already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runPortalRulesMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
});

test('a document without portal rules reads the new field as an empty default', function (): void {
    $document = BusinessRulesDocument::fromArray([]);

    expect($document->portal->inviteValidDays)->toBe(0);
});
