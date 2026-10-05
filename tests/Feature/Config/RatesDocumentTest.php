<?php

declare(strict_types=1);

use App\Support\Config\Documents\RatesDocument;
use Illuminate\Support\Facades\Validator;

test('rules reject an invalid currency, year range, prices and percentages', function (): void {
    $invalid = ratesDocument([
        'currency' => 'EUR',
        'years' => [
            ['year' => 2019, 'suite_pp' => 0, 'owner_pp' => 25000, 'charter_week' => 199500],
        ],
        'terms' => [
            'cabin_deposit_pct' => 101,
            'cabin_balance_days' => 0,
            'charter_deposit_business_days' => 31,
        ],
        'rules' => [
            'child_discounts_per_adult' => 4,
            'festive_supplement_pp' => -1,
        ],
    ]);

    $errors = Validator::make($invalid, RatesDocument::rules())->errors();

    expect($errors->has('currency'))->toBeTrue();
    expect($errors->has('years.0.year'))->toBeTrue();
    expect($errors->has('years.0.suite_pp'))->toBeTrue();
    expect($errors->has('terms.cabin_deposit_pct'))->toBeTrue();
    expect($errors->has('terms.cabin_balance_days'))->toBeTrue();
    expect($errors->has('terms.charter_deposit_business_days'))->toBeTrue();
    expect($errors->has('rules.child_discounts_per_adult'))->toBeTrue();
    expect($errors->has('rules.festive_supplement_pp'))->toBeTrue();
});

test('rules reject years that are not sorted ascending', function (): void {
    $document = ratesDocument();
    $document['years'] = [
        ['year' => 2028, 'suite_pp' => 13965, 'owner_pp' => 26250, 'charter_week' => 209475],
        ['year' => 2027, 'suite_pp' => 13300, 'owner_pp' => 25000, 'charter_week' => 199500],
    ];

    $errors = Validator::make($document, RatesDocument::rules())->errors();

    expect($errors->has('years'))->toBeTrue();
});

test('rules reject a year outside 2020-2100', function (): void {
    $document = ratesDocument();
    $document['years'][0]['year'] = 2101;

    $errors = Validator::make($document, RatesDocument::rules())->errors();

    expect($errors->has('years.0.year'))->toBeTrue();
});

test('warnings flag a year-on-year drop, a 15 percent move and owner not above suite', function (): void {
    $published = RatesDocument::fromArray(RatesDocument::initial());
    $draft = ratesDocument();
    $draft['years'][1]['suite_pp'] = 10000;
    $draft['years'][0]['owner_pp'] = 13300;

    $warnings = RatesDocument::fromArray($draft)->warnings($published);
    $messages = array_map(fn ($warning): string => $warning->message, $warnings);

    expect($messages)->toContain('Suite 2028 is lower than 2027.');
    expect($messages)->toContain('Suite 2028 moves -28.4% vs published — double-check.');
    expect($messages)->toContain("Owner's Suite 2027 is not above the Suite rate.");
});

test('a 15 percent move is not a warning; more than 15 percent is', function (): void {
    $published = RatesDocument::fromArray(RatesDocument::initial());
    $atLimit = ratesDocument();
    $atLimit['years'][0]['suite_pp'] = 15295;
    $atLimit['years'][1]['suite_pp'] = 16000;
    $atLimit['years'][2]['suite_pp'] = 16800;

    expect(RatesDocument::fromArray($atLimit)->warnings($published))->toBe([]);

    $over = ratesDocument();
    $over['years'][0]['suite_pp'] = 15296;
    $over['years'][1]['suite_pp'] = 16000;
    $over['years'][2]['suite_pp'] = 16800;
    $messages = array_map(
        fn ($warning): string => $warning->message,
        RatesDocument::fromArray($over)->warnings($published),
    );

    expect($messages)->toContain('Suite 2027 moves 15.0% vs published — double-check.');
});

test('the change list shows a per-year price edit, not the raw years list', function (): void {
    $published = RatesDocument::fromArray(RatesDocument::initial());
    $draft = ratesDocument();
    $draft['years'][1]['suite_pp'] = 15000;

    $changes = RatesDocument::fromArray($draft)->changesAgainst($published);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->path)->toBe('years.2028.suite_pp');
    expect($changes[0]->label)->toBe('Legacy (yacht) Suite 2028');
    expect($changes[0]->from)->toBe(13965);
    expect($changes[0]->to)->toBe(15000);
});

test('adding a year lists each new price leaf', function (): void {
    $published = RatesDocument::fromArray(RatesDocument::initial());
    $draft = ratesDocument();
    $draft['years'][] = ['year' => 2030, 'suite_pp' => 15396, 'owner_pp' => 28941, 'charter_week' => 230946];

    $changes = RatesDocument::fromArray($draft)->changesAgainst($published);
    $labels = array_map(fn ($change): string => $change->label, $changes);

    expect($labels)->toBe([
        'Legacy (yacht) Suite 2030',
        "Legacy (yacht) Owner's Suite 2030",
        'Legacy (yacht) Charter 2030',
    ]);
    expect($changes[0]->from)->toBeNull();
    expect($changes[0]->to)->toBe(15396);
});
