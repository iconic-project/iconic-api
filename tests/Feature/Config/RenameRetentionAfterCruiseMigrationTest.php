<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const RETENTION_RENAME_APPROVAL = 'Sprint 21: passport_months_after_cruise and medical_days_after_cruise renamed (09 H10)';

function runRetentionRenameMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_140002_rename_retention_after_cruise.php');
    $migration->up();
}

test('the migration renames the retention keys and keeps the numbers', function (): void {
    $document = BusinessRulesDocument::initial();
    $months = $document['retention']['passport_months_after_check_out'];
    $days = $document['retention']['medical_days_after_check_out'];
    unset(
        $document['retention']['passport_months_after_check_out'],
        $document['retention']['medical_days_after_check_out'],
    );
    $document['retention']['passport_months_after_cruise'] = $months;
    $document['retention']['medical_days_after_cruise'] = $days;

    $v1 = new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $v1->save();
    $this->seed(ConfigSeeder::class);

    runRetentionRenameMigration();

    $v1->refresh();
    expect($v1->document['retention'])->toHaveKey('passport_months_after_cruise')
        ->and($v1->document['retention'])->toHaveKey('medical_days_after_cruise')
        ->and($v1->document['retention'])->not->toHaveKey('passport_months_after_check_out');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2)
        ->and($v2->approval_reference)->toBe(RETENTION_RENAME_APPROVAL)
        ->and($v2->document['retention']['passport_months_after_check_out'])->toBe($months)
        ->and($v2->document['retention']['medical_days_after_check_out'])->toBe($days)
        ->and($v2->document['retention'])->not->toHaveKey('passport_months_after_cruise')
        ->and($v2->document['retention'])->not->toHaveKey('medical_days_after_cruise');

    runRetentionRenameMigration();
    expect(BusinessRuleVersion::query()->count())->toBe(2);

    $this->artisan('iconic:config-verify')->assertSuccessful();
});
