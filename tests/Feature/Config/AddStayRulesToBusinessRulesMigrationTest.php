<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const STAY_RULES_APPROVAL = 'Sprint 16: stay added (defaults demo, source 09 H3)';

/**
 * @return array<string, mixed>
 */
function preChangeStayRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['stay']);

    return $document;
}

function runStayRulesMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_03_100001_add_stay_to_business_rules.php');
    $migration->up();
}

function insertPreChangeStayRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeStayRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('a fresh seed already has stay and the migration publishes nothing', function (): void {
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v1: valid');

    runStayRulesMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->firstOrFail()->document['stay']['check_in_time'])->toBe('15:00');

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v1: valid');
});

test('config-verify fails when stay is missing, then the migration publishes one version', function (): void {
    $v1 = insertPreChangeStayRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: stay');

    runStayRulesMigration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('stay');

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(STAY_RULES_APPROVAL);
    expect($v2->document['stay'])->toEqual([
        'check_in_time' => '15:00',
        'check_out_time' => '11:00',
        'no_show_cutoff_time' => '23:59',
        'min_nights' => 1,
        'max_nights' => 30,
        'max_rooms_per_booking' => 5,
        'check_in_requires_full_payment' => true,
        'booking_horizon_days' => 730,
    ]);
    expect(BusinessRuleVersion::query()->count())->toBe(2);

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
