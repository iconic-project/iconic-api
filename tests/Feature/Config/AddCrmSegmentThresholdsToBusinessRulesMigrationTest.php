<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const CRM_SEGMENT_APPROVAL = 'Sprint 9: crm.segment_high_ltv (20000) and crm.segment_mid_ltv (8000) added (PENDING CLIENT, source L2 / prototype segOf)';

/**
 * @return array<string, mixed>
 */
function preChangeCrmSegments(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['crm']);

    return $document;
}

function runCrmSegmentMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_21_200076_add_crm_segment_thresholds_to_business_rules.php');
    $migration->up();
}

function insertPreChangeCrmSegments(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeCrmSegments(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing crm segments, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeCrmSegments();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: crm.segment_high_ltv')
        ->expectsOutputToContain('business_rules v1: crm.segment_mid_ltv');

    runCrmSegmentMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('crm');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(CRM_SEGMENT_APPROVAL);
    expect($v2->document['crm']['segment_high_ltv'])->toBe(20000);
    expect($v2->document['crm']['segment_mid_ltv'])->toBe(8000);

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

test('the migration is a no-op when crm segments are already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runCrmSegmentMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('fromArray defaults missing crm segment keys', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['crm']);

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->crm->segmentHighLtv)->toBe(0);
    expect($lenient->crm->segmentMidLtv)->toBe(0);
});
