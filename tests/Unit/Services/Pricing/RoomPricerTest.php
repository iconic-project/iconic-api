<?php

declare(strict_types=1);

use App\Models\RoomType;
use App\Services\Pricing\NightLine;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\RoomPricer;
use App\Services\Pricing\StayQuote;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoteLine;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Rates\RatePlanOptions;
use App\Support\Rounding;
use App\Support\Stays\StayDates;

/**
 * @return array<string, mixed>
 */
function hotelRatesFixture(): array
{
    $path = dirname(__DIR__, 4).'/docs/requirements/examples/hotel-seed-data.json';
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hotelRatesDocument(array $overrides = []): RatesDocument
{
    $fixture = hotelRatesFixture();

    /** @var array<string, mixed> $document */
    $document = array_replace([
        'currency' => 'USD',
        'schema_version' => 2,
        'years' => [],
        'terms' => [
            'cabin_deposit_pct' => 10,
            'cabin_balance_days' => 60,
            'charter_deposit_pct' => 20,
            'charter_deposit_business_days' => 5,
            'charter_balance_days' => 90,
        ],
        'rules' => [],
        'seasons' => $fixture['seasons'],
        'room_rates' => $fixture['room_rates'],
        'occupancy' => $fixture['occupancy'],
        'day_of_week' => $fixture['day_of_week'],
        'length_of_stay' => $fixture['length_of_stay'],
        'supplements' => $fixture['supplements'],
        'rate_plans' => $fixture['rate_plans'],
    ], $overrides);

    return RatesDocument::fromArray($document);
}

function stayRoomType(string $code, int $baseOccupancy = 2): RoomType
{
    $names = [
        'STD' => 'Standard Double',
        'TWN' => 'Twin',
        'FAM' => 'Family',
        'STE' => 'Suite',
    ];

    return new RoomType([
        'code' => $code,
        'name' => $names[$code] ?? $code,
        'base_occupancy' => $baseOccupancy,
    ]);
}

/**
 * @param  array{room_type: string, check_in: string, nights: int, adults: int, children?: int, child_ages?: list<int>, rate_plan: string, rates_version_id?: int}  $input
 */
function quoteStay(array $input, ?RatesDocument $rates = null, ?RoomType $roomType = null): StayQuote|NoRate
{
    $children = $input['children'] ?? 0;
    $ages = $input['child_ages'] ?? array_fill(0, $children, 8);

    return (new RoomPricer)->quote(
        $rates ?? hotelRatesDocument(),
        $roomType ?? stayRoomType($input['room_type']),
        new StayQuoteInput(
            StayDates::forNights($input['check_in'], $input['nights']),
            $input['room_type'],
            $input['adults'],
            $ages,
            $input['rate_plan'],
            ratesVersionId: $input['rates_version_id'] ?? null,
        ),
    );
}

/**
 * @return list<array{code: string, amount: int}>
 */
function pricedLines(StayQuote $quote): array
{
    return array_map(
        fn (StayQuoteLine $line): array => ['code' => $line->code, 'amount' => $line->amount],
        $quote->lines,
    );
}

test('every reference quote matches the hotel fixture', function (): void {
    /** @var list<array{id: string, input: array{room_type: string, check_in: string, nights: int, adults: int, children: int, rate_plan: string}, expected_lines: list<array{code: string, amount: int}>, expected_total: int}> $quotes */
    $quotes = hotelRatesFixture()['reference_quotes'];

    foreach ($quotes as $quote) {
        $result = quoteStay($quote['input']);

        expect($result)->toBeInstanceOf(StayQuote::class);
        expect(pricedLines($result))->toBe($quote['expected_lines']);
        expect($result->total)->toBe($quote['expected_total']);
    }
});

test('the room line is the room type, the nights and the season names', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 2,
        'adults' => 2,
        'rate_plan' => 'BAR',
        'rates_version_id' => 7,
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect($quote->lines[0]->label())->toBe('Standard Double · 2 nights · Low season');
    expect($quote->ratesVersionId)->toBe(7);
    expect($quote->depositPct)->toBe(30);
    expect($quote->deposit)->toBe(60);
    expect($quote->terms->depositPct)->toBe(30);
    expect($quote->terms->balanceDays)->toBe(21);
    expect($quote->terms->refundable)->toBeTrue();
    expect($quote->terms->cancellationSet)->toBe('standard');
});

test('deposit and balance timing come from the rate plan', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 2,
        'rate_plan' => 'NR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect($quote->total)->toBe(90);
    expect($quote->depositPct)->toBe(100);
    expect($quote->deposit)->toBe(90);
    expect($quote->terms->balanceDays)->toBe(0);
    expect($quote->terms->refundable)->toBeFalse();
    expect($quote->terms->cancellationSummary())->toBe('Non-refundable');
});

test('a stay can cross two seasons', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-03-30',
        'nights' => 3,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect(array_map(fn (NightLine $night): string => $night->season, $quote->nightLines))->toBe([
        'LOW',
        'LOW',
        'SHOULDER',
    ]);
    expect(array_map(fn (NightLine $night): int => $night->base, $quote->nightLines))->toBe([100, 100, 150]);
    expect($quote->lines[0]->amount)->toBe(350);
    expect($quote->lines[0]->label())->toBe('Standard Double · 3 nights · Low, Shoulder seasons');
});

test('a supplement applies only on the nights it covers', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-12-23',
        'nights' => 4,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect(array_map(fn (NightLine $night): int => $night->supplements, $quote->nightLines))->toBe([0, 50, 50, 50]);

    $supplement = null;

    foreach ($quote->lines as $line) {
        if ($line->code === 'supplement') {
            $supplement = $line;
        }
    }

    expect($supplement)->not->toBeNull();
    expect($supplement?->amount)->toBe(150);
    expect($supplement?->label())->toBe('Festive');
});

test('a person supplement multiplies by the guest count', function (): void {
    $rates = hotelRatesDocument([
        'supplements' => [[
            'code' => 'BED',
            'label' => 'Extra bed',
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'per_night' => 10,
            'basis' => 'PERSON',
        ]],
    ]);

    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ], $rates);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect($quote->nightLines[0]->supplements)->toBe(20);
});

test('length-of-stay uses the highest band at each boundary', function (int $nights, int $pct): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => $nights,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);

    $nightSum = array_sum(array_map(fn (NightLine $night): int => $night->total, $quote->nightLines));
    $discount = $pct === 0 ? 0 : Rounding::halfUp($nightSum * $pct / 100);
    $line = null;

    foreach ($quote->lines as $candidate) {
        if ($candidate->code === 'length_of_stay') {
            $line = $candidate;
        }
    }

    if ($pct === 0) {
        expect($line)->toBeNull();
    } else {
        expect($line?->amount)->toBe(-$discount);
    }

    expect($quote->total)->toBe($nightSum - $discount);
})->with([
    '6 nights, below the first band' => [6, 0],
    '7 nights, first band' => [7, 10],
    '8 nights, still the first band' => [8, 10],
    '13 nights, still the first band' => [13, 10],
    '14 nights, second band' => [14, 15],
    '15 nights, still the second band' => [15, 15],
]);

test('friday and saturday take the day-of-week percent from the fixture', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-07-03',
        'nights' => 3,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);

    foreach ($quote->nightLines as $night) {
        $iso = (int) gmdate('N', (int) strtotime($night->night.' UTC'));
        $pct = in_array($iso, [5, 6], true) ? 10 : 0;
        $expected = Rounding::halfUp($night->base * (100 + $pct) / 100) - $night->base;

        expect($night->dow)->toBe($expected);
    }

    expect($quote->nightLines[0]->dow)->toBe(20);
    expect($quote->nightLines[0]->total)->toBe(220);
});

test('a negative single-occupancy percent rounds half up away from zero', function (): void {
    $rates = hotelRatesDocument([
        'seasons' => [[
            'code' => 'FLAT',
            'name' => 'Flat',
            'from' => '2026-02-01',
            'to' => '2026-02-28',
        ]],
        'room_rates' => [[
            'room_type' => 'STD',
            'season' => 'FLAT',
            'nightly' => 15,
        ]],
        'day_of_week' => [],
        'length_of_stay' => [],
        'supplements' => [],
        'occupancy' => [
            'extra_adult_nightly' => 0,
            'extra_child_nightly' => 0,
            'single_occupancy_pct' => -10,
        ],
    ]);

    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 1,
        'rate_plan' => 'BAR',
    ], $rates);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect(Rounding::halfUp(-1.5))->toBe(-2);
    expect($quote->nightLines[0]->single)->toBe(-2);
    expect($quote->total)->toBe(13);
});

test('per-night percentage rounding wins over rounding the stay sum', function (): void {
    $rates = hotelRatesDocument([
        'seasons' => [[
            'code' => 'FLAT',
            'name' => 'Flat',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ]],
        'room_rates' => [[
            'room_type' => 'STD',
            'season' => 'FLAT',
            'nightly' => 5,
        ]],
        'day_of_week' => [],
        'length_of_stay' => [],
        'supplements' => [],
        'occupancy' => [
            'extra_adult_nightly' => 0,
            'extra_child_nightly' => 0,
            'single_occupancy_pct' => 0,
        ],
        'rate_plans' => [[
            'code' => 'UP',
            'name' => 'Up',
            'default' => true,
            'adjust_pct' => 10,
            'refundable' => true,
            'deposit_pct' => 30,
            'balance_days' => 0,
            'cancellation' => 'standard',
            'meal_plan' => 'RO',
        ]],
    ]);

    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-01-05',
        'nights' => 3,
        'adults' => 2,
        'rate_plan' => 'UP',
    ], $rates);

    // Each night is halfUp(5 × 1.10) = 6. Three nights are 18.
    // halfUp(15 × 1.10) is 17, so rounding the stay sum would undercharge by 1.
    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect(array_map(fn (NightLine $night): int => $night->total, $quote->nightLines))->toBe([6, 6, 6]);
    expect($quote->total)->toBe(18);
    expect(Rounding::halfUp(15 * 110 / 100))->toBe(17);
});

test('a missing night is named and later nights are not priced', function (): void {
    $result = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-09-30',
        'nights' => 2,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ]);

    expect($result)->toBeInstanceOf(NoRate::class);
    expect($result->reason)->toBe('No rate for STD on 2026-10-01');
});

test('a season without a room rate names that night', function (): void {
    $rates = hotelRatesDocument([
        'room_rates' => [[
            'room_type' => 'FAM',
            'season' => 'LOW',
            'nightly' => 160,
        ]],
    ]);

    $result = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 2,
        'rate_plan' => 'BAR',
    ], $rates);

    expect($result)->toBeInstanceOf(NoRate::class);
    expect($result->reason)->toBe('No rate for STD on 2026-02-02');
});

test('an unknown rate plan is not priced as zero', function (): void {
    $result = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 2,
        'rate_plan' => 'MISSING',
    ]);

    expect($result)->toBeInstanceOf(NoRate::class);
    expect($result->reason)->toBe('No rate plan MISSING.');
});

test('guest-count limits are not enforced by the pricer', function (): void {
    $quote = quoteStay([
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 4,
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect($quote->nightLines[0]->extras)->toBe(80);
    expect($quote->total)->toBe(180);
});

test('a young child age is still an extra child while infant rules are undefined', function (): void {
    $quote = quoteStay([
        'room_type' => 'FAM',
        'check_in' => '2026-04-06',
        'nights' => 1,
        'adults' => 2,
        'child_ages' => [1],
        'rate_plan' => 'BAR',
    ]);

    expect($quote)->toBeInstanceOf(StayQuote::class);
    expect(pricedLines($quote))->toBe([
        ['code' => 'room', 'amount' => 220],
        ['code' => 'extra_child', 'amount' => 20],
    ]);
    expect($quote->total)->toBe(240);
});

test('the stay total is the night totals minus the length-of-stay discount', function (): void {
    mt_srand(1802);

    $types = ['STD', 'TWN', 'FAM', 'STE'];
    $plans = ['BAR', 'NR'];
    $origin = new DateTimeImmutable('2026-01-01 UTC');
    $end = new DateTimeImmutable('2026-09-30 UTC');

    for ($i = 0; $i < 500; $i++) {
        $nights = mt_rand(1, 16);
        $latest = $end->modify('-'.($nights - 1).' days');
        $span = (int) $origin->diff($latest)->days;
        $checkIn = $origin->modify('+'.mt_rand(0, $span).' days')->format('Y-m-d');
        $adults = mt_rand(1, 3);
        $children = mt_rand(0, 2);

        $quote = quoteStay([
            'room_type' => $types[mt_rand(0, 3)],
            'check_in' => $checkIn,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'rate_plan' => $plans[mt_rand(0, 1)],
        ]);

        expect($quote)->toBeInstanceOf(StayQuote::class);

        $nightSum = array_sum(array_map(fn (NightLine $night): int => $night->total, $quote->nightLines));
        $pct = $nights >= 14 ? 15 : ($nights >= 7 ? 10 : 0);
        $discount = $pct === 0 ? 0 : Rounding::halfUp($nightSum * $pct / 100);

        expect($quote->nightLines)->toHaveCount($nights);
        expect($quote->total)->toBe($nightSum - $discount);
    }
});

test('the same stay under two plans differs only in the plan line, deposit and terms', function (): void {
    $input = [
        'room_type' => 'STD',
        'check_in' => '2026-02-02',
        'nights' => 1,
        'adults' => 2,
    ];

    $bar = quoteStay([...$input, 'rate_plan' => 'BAR']);
    $nr = quoteStay([...$input, 'rate_plan' => 'NR']);

    expect($bar)->toBeInstanceOf(StayQuote::class);
    expect($nr)->toBeInstanceOf(StayQuote::class);

    $withoutPlan = fn (StayQuote $quote): array => array_values(array_filter(
        $quote->lines,
        fn (StayQuoteLine $line): bool => $line->code !== 'rate_plan',
    ));

    expect(array_map(fn (StayQuoteLine $line): array => [$line->code, $line->amount], $withoutPlan($bar)))
        ->toBe(array_map(fn (StayQuoteLine $line): array => [$line->code, $line->amount], $withoutPlan($nr)));
    expect($bar->lines)->toHaveCount(1);
    expect($nr->lines[1]->code)->toBe('rate_plan');
    expect($nr->lines[1]->amount)->toBe(-10);
    expect($bar->total)->toBe(100);
    expect($nr->total)->toBe(90);
    expect($bar->depositPct)->toBe(30);
    expect($bar->deposit)->toBe(30);
    expect($nr->depositPct)->toBe(100);
    expect($nr->deposit)->toBe(90);
    expect($bar->terms->toArray())->toBe([
        'balance_days' => 21,
        'charter' => null,
        'deposit_pct' => 30,
        'refundable' => true,
        'cancellation_set' => 'standard',
    ]);
    expect($nr->terms->toArray())->toBe([
        'balance_days' => 0,
        'charter' => null,
        'deposit_pct' => 100,
        'refundable' => false,
        'cancellation_set' => 'non_refundable',
    ]);
});

test('every published plan is offered for a room type and stay', function (): void {
    $plans = (new RatePlanOptions)->forStay(
        hotelRatesDocument(),
        stayRoomType('STD'),
        StayDates::of('2026-02-02', '2026-02-03'),
    );

    expect(array_map(fn (RatePlan $plan): string => $plan->code, $plans))->toBe(['BAR', 'NR']);
});

test('RoomPricer does not read yacht inventory or published config', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Pricing/RoomPricer.php');

    expect($source)->not->toContain('CabinCategory');
    expect($source)->not->toContain('Departure');
    expect($source)->not->toContain('CurrentConfig');
    expect($source)->not->toContain('Facades\\');
});
