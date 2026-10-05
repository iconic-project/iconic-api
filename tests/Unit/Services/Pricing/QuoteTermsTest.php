<?php

declare(strict_types=1);

use App\Services\Pricing\QuoteTerms;
use App\Support\Config\Documents\CancellationBand;
use App\Support\Config\Documents\Rates\RatePlan;

function planTerms(bool $refundable, string $set): QuoteTerms
{
    return QuoteTerms::forPlan(
        new RatePlan('CODE', 'Plan', false, 0, $refundable, 30, 21, $set, 'RO'),
        [
            new CancellationBand(120, 5),
            new CancellationBand(90, 50),
            new CancellationBand(0, 100),
        ],
    );
}

test('a non-refundable plan ignores the cancellation set', function (): void {
    $terms = planTerms(false, 'non_refundable');

    expect($terms->penaltyPct(200))->toBe(100);
    expect($terms->penaltyPct(100))->toBe(100);
    expect($terms->penaltyPct(0))->toBe(100);
    expect($terms->cancellationSummary())->toBe('Non-refundable');
});

test('a refundable plan uses the cancellation set bands', function (): void {
    $terms = planTerms(true, 'standard');

    expect($terms->penaltyPct(200))->toBe(5);
    expect($terms->penaltyPct(100))->toBe(50);
    expect($terms->penaltyPct(10))->toBe(100);
    expect($terms->cancellationSummary())->toBe('standard');
});

test('yacht quote terms stay on balance days and charter', function (): void {
    $terms = new QuoteTerms(120, null);

    expect($terms->toArray())->toBe([
        'balance_days' => 120,
        'charter' => null,
    ]);
});
