<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\Taxes;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * @return array<string, mixed>
 */
function preChangeTaxes(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['taxes']);

    return $document;
}

function runTaxesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_150001_add_taxes.php');
    $migration->up();
}

test('config-verify fails when taxes are missing, then the migration publishes the fixture list', function (): void {
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeTaxes(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: taxes');

    runTaxesMigration();

    $row->refresh();
    expect($row->document)->not->toHaveKey('taxes');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(Taxes::APPROVAL_REFERENCE);
    expect($v2->asDocument()->toArray()['taxes'])->toBe(Taxes::list());

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

test('production publishes an empty tax list', function (): void {
    $application = app();
    $original = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        expect(Taxes::list())->toBe([]);
    } finally {
        $application->detectEnvironment(fn (): string => $original);
    }
});
