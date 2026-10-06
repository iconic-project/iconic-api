<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\RegistrationRules;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const REGISTRATION_APPROVAL = 'Sprint 21: registration added (09 H15)';

function runRegistrationMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_06_120001_add_registration_to_business_rules.php');
    $migration->up();
}

test('the migration publishes registration and does nothing when it is already present', function (): void {
    $document = BusinessRulesDocument::initial();
    unset($document['registration']);

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

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: registration.fields');

    runRegistrationMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('registration');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->approval_reference)->toBe(REGISTRATION_APPROVAL);
    expect($v2->document['registration'])->toBe(RegistrationRules::defaults());

    runRegistrationMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(2);
});
