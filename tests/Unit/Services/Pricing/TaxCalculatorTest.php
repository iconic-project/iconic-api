<?php

declare(strict_types=1);

use App\Enums\TaxBasis;
use App\Services\Pricing\NightLine;
use App\Services\Pricing\QuoteTerms;
use App\Services\Pricing\StayParty;
use App\Services\Pricing\StayQuote;
use App\Services\Pricing\TaxCalculator;
use App\Support\Config\Documents\Tax;

function taxedStay(int $nights, int $total): StayQuote
{
    $lines = [];

    for ($i = 0; $i < $nights; $i++) {
        $lines[] = new NightLine('2026-02-02', 'LOW', 0, 0, 0, 0, 0, 0, 0);
    }

    return new StayQuote($lines, [], $total, 30, 0, null, new QuoteTerms(21, null));
}

/**
 * @param  list<Tax>  $taxes
 */
function quoteTaxes(StayQuote $quote, StayParty $party, array $taxes): StayQuote
{
    return $quote->withTaxes((new TaxCalculator)->forStay($quote, $party, $taxes));
}

test('a configured city tax shows adults times nights', function (): void {
    $quote = quoteTaxes(
        taxedStay(3, 300),
        new StayParty(2, []),
        [new Tax('CITY', 'City tax', TaxBasis::PerPersonPerNight, 5, 12, false, true)],
    );

    expect($quote->taxLines)->toHaveCount(1);
    expect($quote->taxLines[0]->label())->toBe('City tax · 2 adults × 3 nights');
    expect($quote->taxLines[0]->amount)->toBe(30);
    expect($quote->total)->toBe(300);
    expect($quote->totalIncludingChargedTaxes)->toBe(300);
});

test('each tax basis uses its own count', function (TaxBasis $basis, int $amount, int $expected): void {
    $quote = quoteTaxes(
        taxedStay(3, 200),
        new StayParty(2, []),
        [new Tax('FEE', 'Fee', $basis, $amount, null, true, true)],
    );

    expect($quote->taxLines[0]->amount)->toBe($expected);
    expect($quote->totalIncludingChargedTaxes)->toBe(200 + $expected);
})->with([
    'per stay' => [TaxBasis::PerStay, 15, 15],
    'per night' => [TaxBasis::PerNight, 4, 12],
    'per person per night' => [TaxBasis::PerPersonPerNight, 5, 30],
    'percent of room' => [TaxBasis::PctOfRoom, 10, 20],
]);

test('a percentage of the room total rounds half up', function (): void {
    $quote = quoteTaxes(
        taxedStay(1, 15),
        new StayParty(2, []),
        [new Tax('VAT', 'VAT', TaxBasis::PctOfRoom, 10, null, true, true)],
    );

    expect($quote->taxLines[0]->amount)->toBe(2);
    expect($quote->taxLines[0]->label())->toBe('VAT · 10%');
    expect($quote->totalIncludingChargedTaxes)->toBe(17);
});

test('a child under the exempt age is left out of a per-person tax', function (): void {
    $tax = new Tax('CITY', 'City tax', TaxBasis::PerPersonPerNight, 5, 12, true, true);
    $exempt = quoteTaxes(taxedStay(3, 100), new StayParty(2, [11]), [$tax]);
    $included = quoteTaxes(taxedStay(3, 100), new StayParty(2, [12]), [$tax]);

    expect($exempt->taxLines[0]->label())->toBe('City tax · 2 adults × 3 nights');
    expect($exempt->taxLines[0]->amount)->toBe(30);
    expect($included->taxLines[0]->label())->toBe('City tax · 2 adults, 1 child × 3 nights');
    expect($included->taxLines[0]->amount)->toBe(45);
});

test('an uncharged tax is shown and left out of the amount due', function (): void {
    $quote = quoteTaxes(
        taxedStay(2, 80),
        new StayParty(1, []),
        [new Tax('CITY', 'City tax', TaxBasis::PerNight, 3, null, false, true)],
    );

    expect($quote->taxLines[0]->charged)->toBeFalse();
    expect($quote->taxLines[0]->amount)->toBe(6);
    expect($quote->totalIncludingChargedTaxes)->toBe(80);
});

test('an empty tax list adds no lines', function (): void {
    $quote = quoteTaxes(taxedStay(3, 300), new StayParty(2, [4]), []);

    expect($quote->taxLines)->toBe([]);
    expect($quote->totalIncludingChargedTaxes)->toBe(300);
});
