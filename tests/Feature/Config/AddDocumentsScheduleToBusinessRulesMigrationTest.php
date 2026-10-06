<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const DOCUMENTS_SCHEDULE_APPROVAL = 'Sprint 7: documents.pretrip_days_before (45) and documents.voucher_days_before (7) added (source J7 / prototype T−45 · T−7)';

/**
 * @return array<string, mixed>
 */
function preChangeDocumentsSchedule(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['documents']);

    return $document;
}

function runDocumentsScheduleMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_21_200041_add_documents_schedule_to_business_rules.php');
    $migration->up();
}

function insertPreChangeDocumentsSchedule(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeDocumentsSchedule(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing documents schedule, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeDocumentsSchedule();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: documents.pretrip_days_before')
        ->expectsOutputToContain('business_rules v1: documents.voucher_days_before');

    runDocumentsScheduleMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('documents');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(DOCUMENTS_SCHEDULE_APPROVAL);
    expect($v2->document['documents']['pre_arrival_days_before'])->toBe(45);
    expect($v2->document['documents'])->not->toHaveKey('pretrip_days_before');
    expect($v2->document['documents']['voucher_days_before'])->toBe(7);

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

test('the migration is a no-op when documents schedule is already present', function (): void {
    $document = BusinessRulesDocument::initial();
    $document['documents']['pretrip_days_before'] = 45;

    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'ALREADY',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    runDocumentsScheduleMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('fromArray defaults missing documents schedule keys', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['documents']);

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->documents->preArrivalDaysBefore)->toBe(0);
    expect($lenient->documents->voucherDaysBefore)->toBe(0);
});
