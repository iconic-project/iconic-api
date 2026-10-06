<?php

declare(strict_types=1);

use App\Enums\RoomTypeStatus;
use App\Models\ChangeHistory;
use App\Models\Property;
use App\Models\RateVersion;
use App\Models\RoomType;
use App\Support\Config\Documents\Rates\RatesV2;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * @param  list<string>  $codes
 */
function hotelRoomTypes(array $codes): void
{
    $property = Property::factory()->create();

    foreach ($codes as $index => $code) {
        RoomType::query()->create([
            'property_id' => $property->id,
            'code' => $code,
            'name' => $code,
            'base_occupancy' => 2,
            'max_occupancy' => 2,
            'max_adults' => 2,
            'max_children' => 0,
            'sort' => $index,
            'status' => RoomTypeStatus::Active,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function ratesBeforeV2(): array
{
    $document = ratesDocument();
    $document['rules']['festive_supplement_pp'] = 751;

    foreach ([
        'schema_version',
        'seasons',
        'room_rates',
        'occupancy',
        'day_of_week',
        'length_of_stay',
        'supplements',
        'rate_plans',
    ] as $key) {
        unset($document[$key]);
    }

    return $document;
}

function insertRatesBeforeV2(): RateVersion
{
    $row = new RateVersion([
        'version' => 1,
        'document' => ratesBeforeV2(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

function runRatesV2Migration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_130001_add_rates_document_v2.php');
    $migration->up();
}

test('config-verify fails on a pre-v2 rates document, then the migration publishes the hotel fixture', function (): void {
    hotelRoomTypes(['STD', 'TWN', 'FAM', 'STE']);
    $v1 = insertRatesBeforeV2();
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('rates v1:');

    runRatesV2Migration();
    runRatesV2Migration();

    $v1->refresh();
    expect($v1->document)->not->toHaveKey('schema_version');
    expect($v1->document['rules']['festive_supplement_pp'])->toBe(751);

    $v2 = RateVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(RatesV2::APPROVAL_REFERENCE);
    expect($v2->document['schema_version'])->toBe(2);
    expect($v2->document)->not->toHaveKey('years');
    expect($v2->document)->not->toHaveKey('terms');
    expect($v2->document)->not->toHaveKey('rules');
    expect($v2->asDocument()->toArray()['seasons'])->toBe(hotelFixture('seasons'));
    expect($v2->document['room_rates'])->toHaveCount(16);
    expect($v2->document['occupancy']['extra_adult_nightly'])->toBe(40);

    $history = ChangeHistory::query()
        ->where('event', 'rates.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_label)->toBe('System');

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('rates v2: valid');
});

test('the migration keeps room rates only for active room types that exist', function (): void {
    hotelRoomTypes(['STD']);
    insertRatesBeforeV2();
    $this->seed(ConfigSeeder::class);

    runRatesV2Migration();

    $v2 = RateVersion::query()->orderByDesc('version')->firstOrFail();
    $types = array_unique(array_column($v2->document['room_rates'], 'room_type'));

    expect($types)->toBe(['STD']);
    expect($v2->document['room_rates'])->toHaveCount(4);
});

test('outside local and testing the migration publishes empty lists', function (): void {
    insertRatesBeforeV2();
    $this->seed(ConfigSeeder::class);
    $application = app();
    $original = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        runRatesV2Migration();
    } finally {
        $application->detectEnvironment(fn () => $original);
    }

    $v2 = RateVersion::query()->orderByDesc('version')->firstOrFail();

    expect($v2->approval_reference)->toBe(RatesV2::APPROVAL_REFERENCE);
    expect($v2->document['schema_version'])->toBe(2);
    expect($v2->document['seasons'])->toBe([]);
    expect($v2->document['room_rates'])->toBe([]);
    expect($v2->document['length_of_stay'])->toBe([]);
    expect($v2->document['supplements'])->toBe([]);
    expect($v2->document['rate_plans'])->toBe([]);
    expect($v2->document['occupancy'])->toBe([
        'extra_adult_nightly' => 0,
        'extra_child_nightly' => 0,
        'single_occupancy_pct' => 0,
    ]);
    expect($v2->document['rules']['festive_supplement_pp'])->toBe(751);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('rates v2: valid');
});

test('a fresh database seeds schema version 2 and config-verify accepts it', function (): void {
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('rates v1: valid');

    $row = RateVersion::query()->firstOrFail();
    expect($row->version)->toBe(1);
    expect($row->document['schema_version'])->toBe(2);
    expect($row->asDocument()->toArray()['seasons'])->toBe(hotelFixture('seasons'));
    expect($row->document['room_rates'])->toBe([]);

    runRatesV2Migration();

    expect(RateVersion::query()->count())->toBe(1);
});
