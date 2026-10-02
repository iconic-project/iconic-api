<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

const HOLDS_BUSINESS_HOURS_APPROVAL = 'Sprint 4: holds business hours added (defaults Mon–Fri 09:00–18:00, near-term ≤ 120 days, source TEC-004 pending client)';

/**
 * @return array<string, mixed>
 */
function preChangeBusinessRules(): array
{
    $document = BusinessRulesDocument::initial();
    unset(
        $document['holds']['business_days'],
        $document['holds']['business_day_start'],
        $document['holds']['business_day_end'],
        $document['holds']['holidays'],
        $document['holds']['near_term_max_days'],
    );

    return $document;
}

function runHoldsBusinessHoursMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_20_200019_add_holds_business_hours_to_business_rules.php');
    $migration->up();
}

function insertPreChangeBusinessRules(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeBusinessRules(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails on a latest document missing the five hold fields, then the migration publishes v2', function (): void {
    $v1 = insertPreChangeBusinessRules();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: holds.business_days')
        ->expectsOutputToContain('business_rules v1: holds.business_day_start')
        ->expectsOutputToContain('business_rules v1: holds.business_day_end')
        ->expectsOutputToContain('business_rules v1: holds.holidays')
        ->expectsOutputToContain('business_rules v1: holds.near_term_max_days');

    runHoldsBusinessHoursMigration();

    $v1->refresh();
    expect($v1->document['holds'])->not->toHaveKeys([
        'business_days',
        'business_day_start',
        'business_day_end',
        'holidays',
        'near_term_max_days',
    ]);

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(HOLDS_BUSINESS_HOURS_APPROVAL);
    expect($v2->document['holds']['business_days'])->toBe([1, 2, 3, 4, 5]);
    expect($v2->document['holds']['business_day_start'])->toBe('09:00');
    expect($v2->document['holds']['business_day_end'])->toBe('18:00');
    expect($v2->document['holds']['holidays'])->toBe([]);
    expect($v2->document['holds']['near_term_max_days'])->toBe(120);
    expect($v2->document['holds']['near_term_business_hours'])->toBe(48);

    $history = ChangeHistory::query()
        ->where('event', 'business_rules.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_id)->toBeNull();
    expect($history?->actor_label)->toBe('System');
    expect($history?->reason)->toBe(HOLDS_BUSINESS_HOURS_APPROVAL);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v2: valid');
});

test('the migration adds only the missing hold keys', function (): void {
    $document = preChangeBusinessRules();
    $document['holds']['business_days'] = [1, 2, 3];

    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE-PARTIAL',
        'published_at' => now(),
    ]);
    $row->save();

    runHoldsBusinessHoursMigration();

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->document['holds']['business_days'])->toBe([1, 2, 3]);
    expect($v2->document['holds']['business_day_start'])->toBe('09:00');
    expect($v2->document['holds']['near_term_max_days'])->toBe(120);
});

test('the migration is a no-op when the five fields are already present', function (): void {
    $this->seed(ConfigSeeder::class);

    runHoldsBusinessHoursMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(1);
    expect(BusinessRuleVersion::query()->value('version'))->toBe(1);
});

test('the migration is a no-op when no business-rules version exists', function (): void {
    runHoldsBusinessHoursMigration();

    expect(BusinessRuleVersion::query()->count())->toBe(0);
});
