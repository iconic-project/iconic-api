<?php

declare(strict_types=1);

use App\Support\Config\Documents\RatesDocument;

test('a stored yacht rate table is ignored and is not written back', function (): void {
    $stored = RatesDocument::fromArray([
        'currency' => 'USD',
        'schema_version' => 2,
        'years' => [
            ['year' => 2027, 'suite_pp' => 13300, 'owner_pp' => 25000, 'charter_week' => 199500],
        ],
        'rules' => [
            'single_supplement_pct' => 75,
            'festive_supplement_charter' => 12000,
        ],
        'terms' => [
            'cabin_deposit_pct' => 10,
            'charter_deposit_pct' => 20,
        ],
    ])->toArray();

    expect($stored['currency'])->toBe('USD');
    expect($stored)->not->toHaveKey('years');
    expect($stored)->not->toHaveKey('rules');
    expect($stored)->not->toHaveKey('terms');
    expect($stored)->toHaveKey('rate_plans');
    expect($stored)->toHaveKey('room_rates');
});
