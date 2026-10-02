<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const LEGAL_ENTITY_APPROVAL = 'Sprint 7: legal_entity added (defaults from decision row 8; bank [TBD], source LEG-004 pending client)';

/**
 * @return array<string, mixed>
 */
function preChangeLegalEntity(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['legal_entity']);

    return $document;
}

function runLegalEntityMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_21_200038_add_legal_entity_to_business_rules.php');
    $migration->up();
}

function insertPreChangeLegalEntity(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeLegalEntity(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing legal_entity, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeLegalEntity();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: legal_entity.name')
        ->expectsOutputToContain('business_rules v1: legal_entity.bank.swift');

    runLegalEntityMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('legal_entity');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(LEGAL_ENTITY_APPROVAL);
    expect($v2->document['legal_entity']['name'])->toBe('PONTOS LLC (a limited liability company)');
    expect($v2->document['legal_entity']['ein'])->toBe('42-4742064');
    expect($v2->document['legal_entity']['bank']['bank_name'])->toBe('[TBD]');
    expect($v2->document['legal_entity']['bank']['swift'])->toBe('[TBD]');
    expect($v2->document['legal']['consent_versions']['terms'])->toBe('v2026.1 (text pending LEG-001)');

    $history = ChangeHistory::query()
        ->where('event', 'business_rules.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_id)->toBeNull();
    expect($history?->actor_label)->toBe('System');
    expect($history?->reason)->toBe(LEGAL_ENTITY_APPROVAL);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v2: valid');
});

test('the migration adds only the missing legal_entity keys', function (): void {
    $document = preChangeLegalEntity();
    $document['legal_entity'] = [
        'name' => 'Custom Entity',
        'bank' => [
            'bank_name' => 'Existing Bank',
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

    runLegalEntityMigration();

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->document['legal_entity']['name'])->toBe('Custom Entity');
    expect($v2->document['legal_entity']['email'])->toBe('info@iconic.co');
    expect($v2->document['legal_entity']['bank']['bank_name'])->toBe('Existing Bank');
    expect($v2->document['legal_entity']['bank']['swift'])->toBe('[TBD]');
});

test('the migration is a no-op when legal_entity is already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runLegalEntityMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('the migration is a no-op when no business-rules version exists', function (): void {
    runLegalEntityMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(0);
});
