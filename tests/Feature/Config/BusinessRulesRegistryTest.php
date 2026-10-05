<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Enums\RuleWhere;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessRules\Registry;
use App\Support\Config\DocumentDiff;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the stay group lists eight labelled demo values and ops-001 is marked retired', function (): void {
    $rows = collect(Registry::rows(app(CurrentConfig::class)));
    $stay = $rows->where('group', 'stay');

    expect($stay)->toHaveCount(8);
    expect($stay->pluck('name')->all())->toBe([
        'Check-in time',
        'Check-out time',
        'No-show cutoff',
        'Minimum nights',
        'Maximum nights',
        'Maximum rooms per booking',
        'Check-in requires full payment',
        'Booking horizon',
    ]);
    expect($stay->every(fn (array $row): bool => $row['group_label'] === 'Stay'))->toBeTrue();
    expect($stay->every(fn (array $row): bool => $row['status'] === 'PENDING_CLIENT'))->toBeTrue();
    expect($stay->pluck('current_display')->all())->toBe([
        '15:00',
        '11:00',
        '23:59',
        '1 night',
        '30 nights',
        '5 rooms',
        'Yes',
        '730 days',
    ]);

    $duration = $rows->firstWhere('key', 'ops-001-duration');
    expect($duration)->not->toBeNull();
    expect($duration['note'])->toContain('09 H2');
    expect($duration['current_display'])->toBe('7 nights · Sunday → Sunday');
});

test('every here path exists on the document and every leaf is covered once', function (): void {
    $leaves = DocumentDiff::leafPaths(BusinessRulesDocument::fromArray(BusinessRulesDocument::initial())->toArray());
    $here = Registry::herePaths();

    sort($leaves);
    $sortedHere = $here;
    sort($sortedHere);

    expect($here)->toHaveCount(count($leaves));
    expect($sortedHere)->toBe($leaves);
    expect(array_unique($here))->toHaveCount(count($here));
});

test('a changed commission cap marks the FIN-005 row as differing', function (): void {
    $document = businessRulesDocument();
    $document['commission']['cap_pct'] = 15;

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        1,
        'BOARD-CAP',
        adminUser(),
    );

    $row = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'fin-005-commission-cap');

    expect($row['differs'])->toBeTrue();
    expect($row['current_display'])->toBe('15%');
});

test('publishing a legacy suite price is refused and FIN-001 stays on the seeded rates', function (): void {
    $document = ratesDocument();
    $document['years'][0]['suite_pp'] = 13000;

    expect(fn () => app(ConfigPublisher::class)->publish(
        ConfigKind::Rates,
        $document,
        1,
        'BOARD-RATES',
        adminUser(),
    ))->toThrow(ValidationException::class);

    $row = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'fin-001-base-rates');

    expect($row['differs'])->toBeFalse();
});

test('a changed engine child age marks the OPS-004 row as differing', function (): void {
    $document = engineSettingsDocument();
    $document['guests']['child_min_age'] = 7;

    app(ConfigPublisher::class)->publish(
        ConfigKind::EngineSettings,
        $document,
        1,
        'BOARD-ENGINE',
        adminUser(),
    );

    $row = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-004-child-age');

    expect($row['differs'])->toBeTrue();
    expect($row['current_display'])->toBe('7 years');
});

test('a charter sla that does not match the response sla is flagged', function (): void {
    $document = engineSettingsDocument();
    $document['charter']['response_sla_hours'] = 12;

    app(ConfigPublisher::class)->publish(
        ConfigKind::EngineSettings,
        $document,
        1,
        'BOARD-SLA',
        adminUser(),
    );

    $row = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-009-charter-sla');

    expect($row['differs'])->toBeTrue();
});

test('reordered band keys and a json-round-tripped reminder list do not differ', function (): void {
    $sourceBands = data_get(BusinessRulesDocument::initial(), 'cancellation.bands');
    $reordered = [
        ['penalty_pct' => 5, 'min_days' => 120],
        ['penalty_pct' => 50, 'min_days' => 90],
        ['penalty_pct' => 100, 'min_days' => 0],
    ];
    $typed = BusinessRulesDocument::fromArray(businessRulesDocument([
        'cancellation' => ['bands' => array_reverse($sourceBands)],
    ]));

    expect(DocumentDiff::equal($typed->toArray()['cancellation']['bands'], $sourceBands))->toBeTrue();
    expect(DocumentDiff::equal($reordered, $sourceBands))->toBeTrue();

    $reminders = json_decode((string) json_encode([21, 7]), true);
    expect(DocumentDiff::equal($reminders, [21, 7]))->toBeTrue();

    $cancellation = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'cancellation-bands');
    $balance = collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'balance-reminders');

    expect($cancellation['differs'])->toBeFalse();
    expect($balance['differs'])->toBeFalse();
    expect($cancellation['where'])->toBe(RuleWhere::Here->value);
});
