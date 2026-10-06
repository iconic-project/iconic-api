<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RateVersion;
use App\Models\RoomType;
use App\Services\Config\ConfigPublisher;
use App\Support\Config\Documents\RatesDocument;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function activeRoomType(string $code, RoomTypeStatus $status = RoomTypeStatus::Active): RoomType
{
    $property = Property::query()->first() ?? Property::factory()->create();

    return RoomType::query()->create([
        'property_id' => $property->id,
        'code' => $code,
        'name' => $code === 'STD' ? 'Standard Double' : $code,
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 0,
        'status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $replace
 * @return array<string, mixed>
 */
function ratesV2(array $replace = []): array
{
    $document = ratesDocument();

    foreach ($replace as $key => $value) {
        $document[$key] = $value;
    }

    return $document;
}

test('rates v2 rules reject one invalid case each', function (string $path, mixed $value, string $error): void {
    $document = ratesDocument();
    data_set($document, $path, $value);

    expect(Validator::make($document, RatesDocument::rules())->errors()->has($error))->toBeTrue();
})->with([
    'schema version' => ['schema_version', 1, 'schema_version'],
    'duplicate season codes' => ['seasons.1.code', 'LOW', 'seasons.1.code'],
    'extra adult below zero' => ['occupancy.extra_adult_nightly', -1, 'occupancy.extra_adult_nightly'],
    'extra child below zero' => ['occupancy.extra_child_nightly', -1, 'occupancy.extra_child_nightly'],
    'single occupancy above 100' => ['occupancy.single_occupancy_pct', 101, 'occupancy.single_occupancy_pct'],
    'single occupancy below -100' => ['occupancy.single_occupancy_pct', -101, 'occupancy.single_occupancy_pct'],
    'weekday above 100' => ['day_of_week.1', 101, 'day_of_week.1'],
    'weekday below -100' => ['day_of_week.7', -101, 'day_of_week.7'],
    'length of stay under 2' => ['length_of_stay.0.min_nights', 1, 'length_of_stay.0.min_nights'],
    'length of stay discount above 100' => ['length_of_stay.0.discount_pct', 101, 'length_of_stay.0.discount_pct'],
    'supplement per night below zero' => ['supplements.0.per_night', -1, 'supplements.0.per_night'],
    'supplement basis' => ['supplements.0.basis', 'NIGHT', 'supplements.0.basis'],
    'rate plan adjustment above 100' => ['rate_plans.0.adjust_pct', 101, 'rate_plans.0.adjust_pct'],
    'rate plan adjustment below -100' => ['rate_plans.0.adjust_pct', -101, 'rate_plans.0.adjust_pct'],
    'rate plan deposit above 100' => ['rate_plans.0.deposit_pct', 101, 'rate_plans.0.deposit_pct'],
    'rate plan balance days above 365' => ['rate_plans.0.balance_days', 366, 'rate_plans.0.balance_days'],
    'meal plan' => ['rate_plans.0.meal_plan', 'AI', 'rate_plans.0.meal_plan'],
]);

test('single occupancy of -100 is allowed', function (): void {
    $document = ratesDocument();
    $document['occupancy']['single_occupancy_pct'] = -100;

    expect(Validator::make($document, RatesDocument::rules())->errors()->has('occupancy.single_occupancy_pct'))->toBeFalse();
});

test('a missing weekday means zero and an unknown weekday is refused', function (): void {
    $document = ratesDocument();
    unset($document['day_of_week'][3]);

    expect(Validator::make($document, RatesDocument::rules())->fails())->toBeFalse();
    expect(RatesDocument::fromArray($document)->dayOfWeek->wednesday)->toBe(0);

    $document['day_of_week'][8] = 0;
    $errors = Validator::make($document, RatesDocument::rules())->errors();

    expect($errors->has('day_of_week'))->toBeTrue();
    expect($errors->first('day_of_week'))->toContain('1 to 7');
});

test('season ranges allow a gap and a shared boundary night is an overlap', function (): void {
    $touching = ratesV2([
        'seasons' => [
            ['code' => 'LOW', 'name' => 'Low', 'from' => '2026-01-01', 'to' => '2026-03-31'],
            ['code' => 'SHOULDER', 'name' => 'Shoulder', 'from' => '2026-04-01', 'to' => '2026-06-30'],
        ],
        'room_rates' => [],
        'supplements' => [],
    ]);

    expect(Validator::make($touching, RatesDocument::rules())->fails())->toBeFalse();

    $gap = $touching;
    $gap['seasons'][1]['from'] = '2026-06-01';

    expect(Validator::make($gap, RatesDocument::rules())->fails())->toBeFalse();

    $oneNight = $touching;
    $oneNight['seasons'] = [
        ['code' => 'LOW', 'name' => 'Low', 'from' => '2026-03-31', 'to' => '2026-03-31'],
    ];

    expect(Validator::make($oneNight, RatesDocument::rules())->fails())->toBeFalse();

    $overlap = $touching;
    $overlap['seasons'][0]['to'] = '2026-04-01';
    $errors = Validator::make($overlap, RatesDocument::rules())->errors();

    expect($errors->first('seasons'))->toContain('LOW')->toContain('SHOULDER');

    $backwards = $touching;
    $backwards['seasons'][0]['from'] = '2026-04-02';
    $backwards['seasons'][0]['to'] = '2026-03-31';

    expect(Validator::make($backwards, RatesDocument::rules())->errors()->first('seasons'))->toContain('LOW');
});

test('a publish with overlapping seasons is refused naming both season codes', function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $document = ratesV2([
        'seasons' => [
            ['code' => 'LOW', 'name' => 'Low', 'from' => '2026-01-01', 'to' => '2026-04-01'],
            ['code' => 'SHOULDER', 'name' => 'Shoulder', 'from' => '2026-04-01', 'to' => '2026-06-30'],
        ],
        'room_rates' => [],
        'supplements' => [],
    ]);

    $response = $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-OVERLAP',
        ])
        ->assertUnprocessable();

    expect($response->json('errors')['document.seasons'][0] ?? '')
        ->toContain('LOW')
        ->toContain('SHOULDER');
    expect(RateVersion::query()->count())->toBe(1);
});

test('room rates must be unique, name a known season, and use an active room type', function (): void {
    activeRoomType('STD');
    activeRoomType('OLD', RoomTypeStatus::Inactive);
    $valid = ratesV2([
        'room_rates' => [
            ['room_type' => 'STD', 'season' => 'LOW', 'nightly' => 100],
            ['room_type' => 'STD', 'season' => 'HIGH', 'nightly' => 200],
        ],
    ]);

    expect(Validator::make($valid, RatesDocument::rules())->fails())->toBeFalse();

    $duplicate = $valid;
    $duplicate['room_rates'][1]['season'] = 'LOW';
    expect(Validator::make($duplicate, RatesDocument::rules())->errors()->first('room_rates'))
        ->toContain('STD')
        ->toContain('LOW');

    $unknownSeason = $valid;
    $unknownSeason['room_rates'] = [
        ['room_type' => 'STD', 'season' => 'NONE', 'nightly' => 100],
    ];
    expect(Validator::make($unknownSeason, RatesDocument::rules())->errors()->first('room_rates.0.season'))
        ->toContain('NONE');

    $inactive = $valid;
    $inactive['room_rates'] = [
        ['room_type' => 'OLD', 'season' => 'LOW', 'nightly' => 100],
    ];
    expect(Validator::make($inactive, RatesDocument::rules())->errors()->has('room_rates.0.room_type'))->toBeTrue();

    $missing = $valid;
    $missing['room_rates'] = [
        ['room_type' => 'NOPE', 'season' => 'LOW', 'nightly' => 100],
    ];
    expect(Validator::make($missing, RatesDocument::rules())->errors()->has('room_rates.0.room_type'))->toBeTrue();

    $free = $valid;
    $free['room_rates'] = [
        ['room_type' => 'STD', 'season' => 'LOW', 'nightly' => 0],
    ];
    expect(Validator::make($free, RatesDocument::rules())->errors()->has('room_rates.0.nightly'))->toBeTrue();
});

test('length of stay bands must be distinct and ascending', function (): void {
    $document = ratesDocument();
    $document['length_of_stay'] = [
        ['min_nights' => 14, 'discount_pct' => 15],
        ['min_nights' => 7, 'discount_pct' => 10],
    ];

    expect(Validator::make($document, RatesDocument::rules())->errors()->first('length_of_stay'))
        ->toContain('ascending');

    $document['length_of_stay'] = [
        ['min_nights' => 7, 'discount_pct' => 10],
        ['min_nights' => 7, 'discount_pct' => 15],
    ];

    expect(Validator::make($document, RatesDocument::rules())->errors()->has('length_of_stay.0.min_nights'))->toBeTrue();
});

test('a supplement that ends before it starts is refused', function (): void {
    $document = ratesDocument();
    $document['supplements'][0]['from'] = '2026-12-27';
    $document['supplements'][0]['to'] = '2026-12-24';

    expect(Validator::make($document, RatesDocument::rules())->errors()->first('supplements'))
        ->toContain('FESTIVE');
});

test('rate plans need exactly one default when any plan is listed', function (): void {
    $none = ratesDocument();
    $none['rate_plans'][0]['default'] = false;
    $none['rate_plans'][1]['default'] = false;

    expect(Validator::make($none, RatesDocument::rules())->errors()->first('rate_plans'))
        ->toContain('Exactly one rate plan');

    $both = ratesDocument();
    $both['rate_plans'][1]['default'] = true;

    expect(Validator::make($both, RatesDocument::rules())->errors()->first('rate_plans'))
        ->toContain('Exactly one rate plan');

    $empty = ratesDocument();
    $empty['rate_plans'] = [];

    expect(Validator::make($empty, RatesDocument::rules())->fails())->toBeFalse();
});

test('duplicate rate plan codes are refused', function (): void {
    $document = ratesDocument();
    $document['rate_plans'][1]['code'] = 'BAR';

    expect(Validator::make($document, RatesDocument::rules())->errors()->has('rate_plans.1.code'))->toBeTrue();
});

test('legacy rate keys are ignored on publish', function (string $path, int $value): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $document = ratesDocument();
    data_set($document, $path, $value);

    try {
        app(ConfigPublisher::class)->publish(ConfigKind::Rates, $document, 1, 'BOARD-LEGACY', adminUser());
    } catch (ValidationException $exception) {
        expect($exception->errors()['document'][0] ?? '')->toContain('Nothing to publish');
    }

    $stored = RateVersion::query()->firstOrFail()->document;

    expect(RateVersion::query()->count())->toBe(1);
    expect($stored)->not->toHaveKey('years');
    expect($stored)->not->toHaveKey('rules');
    expect($stored)->not->toHaveKey('terms');
})->with([
    'suite price' => ['years.0.suite_pp', 13000],
    'single supplement' => ['rules.single_supplement_pct', 70],
    'triple discount' => ['rules.triple_discount_pct', 11],
    'child discount' => ['rules.child_discount_pct', 10],
    'child discounts per adult' => ['rules.child_discounts_per_adult', 2],
    'child discounts per cabin' => ['rules.child_discounts_per_cabin', 1],
    'back to back' => ['rules.back_to_back_pct', 6],
    'festive guest' => ['rules.festive_supplement_pp', 751],
    'festive charter' => ['rules.festive_supplement_charter', 12001],
]);

test('warnings name a room type missing a season, an uncovered horizon and a supplement outside every season', function (): void {
    CarbonImmutable::setTestNow('2026-02-01 12:00:00');
    $this->seed(ConfigSeeder::class);
    activeRoomType('STD');
    activeRoomType('OLD', RoomTypeStatus::Inactive);

    $document = ratesV2([
        'seasons' => [
            ['code' => 'LOW', 'name' => 'Low', 'from' => '2026-02-01', 'to' => '2026-02-28'],
        ],
        'room_rates' => [],
        'supplements' => [
            ['code' => 'FESTIVE', 'label' => 'Festive', 'from' => '2026-06-01', 'to' => '2026-06-03', 'per_night' => 50, 'basis' => 'ROOM'],
        ],
    ]);

    $messages = array_map(
        fn ($warning): string => $warning->message,
        RatesDocument::fromArray($document)->warnings(RatesDocument::fromArray(ratesDocument())),
    );

    expect($messages)->toContain('Standard Double (STD) has no rate in Low (LOW).');
    expect(implode(' ', $messages))->toContain('Nights from 2026-03-01');
    expect(implode(' ', $messages))->toContain('Festive (FESTIVE) includes nights outside every season.');
    expect(implode(' ', $messages))->not->toContain('OLD');

    $covered = ratesV2([
        'seasons' => [
            ['code' => 'YEAR', 'name' => 'Year', 'from' => '2026-02-01', 'to' => '2028-02-01'],
        ],
        'room_rates' => [
            ['room_type' => 'STD', 'season' => 'YEAR', 'nightly' => 100],
        ],
        'supplements' => [
            ['code' => 'FESTIVE', 'label' => 'Festive', 'from' => '2026-06-01', 'to' => '2026-06-03', 'per_night' => 50, 'basis' => 'ROOM'],
        ],
    ]);

    $coveredMessages = array_map(
        fn ($warning): string => $warning->path,
        RatesDocument::fromArray($covered)->warnings(null),
    );

    expect($coveredMessages)->not->toContain('seasons');
    expect($coveredMessages)->not->toContain('room_rates');
    expect($coveredMessages)->not->toContain('supplements.0');
});

test('the change list labels every v2 path', function (): void {
    $published = RatesDocument::fromArray(ratesDocument());
    $draft = ratesDocument();
    $draft['seasons'][0]['name'] = 'Quiet';
    $draft['occupancy']['extra_child_nightly'] = 25;
    $draft['day_of_week'][5] = 15;
    $draft['length_of_stay'][0]['discount_pct'] = 12;
    $draft['supplements'][0]['per_night'] = 60;
    $draft['rate_plans'][0]['adjust_pct'] = -5;

    $changes = RatesDocument::fromArray($draft)->changesAgainst($published);
    $byPath = [];

    foreach ($changes as $change) {
        $byPath[$change->path] = $change->label;
        expect($change->label)->not->toBe($change->path);
    }

    expect($byPath['seasons.LOW.name'] ?? null)->toBe('Season Quiet');
    expect($byPath['occupancy.extra_child_nightly'] ?? null)->toBe('Extra child / night');
    expect($byPath['day_of_week.5'] ?? null)->toBe('Friday adjustment %');
    expect($byPath['length_of_stay.7'] ?? null)->toBe('7 nights or more — discount %');
    expect($byPath['supplements.FESTIVE.per_night'] ?? null)->toBe('Festive (FESTIVE) per night');
    expect($byPath['rate_plans.BAR.adjust_pct'] ?? null)->toBe('Best available (BAR) adjustment %');
});
