<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const SURVEY_HOURS_APPROVAL = 'Sprint 21: survey_hours_after_return renamed (09 H10)';

function runSurveyHoursMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_130001_rename_survey_hours_after_return.php');
    $migration->up();
}

test('the migration renames the survey hour key and keeps the number', function (): void {
    $document = BusinessRulesDocument::initial();
    $hours = $document['nps']['survey_hours_after_check_out'];
    unset($document['nps']['survey_hours_after_check_out']);
    $document['nps']['survey_hours_after_return'] = $hours;

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

    runSurveyHoursMigration();

    $v1->refresh();
    expect($v1->document['nps'])->toHaveKey('survey_hours_after_return')
        ->and($v1->document['nps'])->not->toHaveKey('survey_hours_after_check_out');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2)
        ->and($v2->approval_reference)->toBe(SURVEY_HOURS_APPROVAL)
        ->and($v2->document['nps']['survey_hours_after_check_out'])->toBe($hours)
        ->and($v2->document['nps'])->not->toHaveKey('survey_hours_after_return');

    runSurveyHoursMigration();
    expect(BusinessRuleVersion::query()->count())->toBe(2);

    $this->artisan('iconic:config-verify')->assertSuccessful();
});
