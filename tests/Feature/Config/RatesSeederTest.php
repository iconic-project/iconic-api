<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\Property;
use App\Models\RateVersion;
use App\Models\RoomType;
use App\Services\Config\ConfigRegistry;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\RatesDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded rates document is the hotel rate card', function (): void {
    $this->seed(ConfigSeeder::class);

    $document = RatesDocument::initial();

    expect($document)->not->toHaveKey('years')
        ->and($document)->not->toHaveKey('terms')
        ->and($document)->not->toHaveKey('rules');
    expect($document['schema_version'])->toBe(2);
    expect($document['seasons'])->toBe(hotelFixture('seasons'));
    expect($document['occupancy'])->toBe(hotelFixture('occupancy'));
    expect($document['length_of_stay'])->toBe(hotelFixture('length_of_stay'));
    expect($document['supplements'])->toBe(hotelFixture('supplements'));
    expect($document['room_rates'])->toBe([]);
    expect(collect($document['rate_plans'])->firstWhere('default', true)['code'])->toBe('BAR');

    $row = RateVersion::query()->firstOrFail();
    expect($row->version)->toBe(1);
    expect($row->asDocument()->toArray())->toBe($document);
    expect(app(CurrentConfig::class)->rates()->toArray())->toBe($document);
});

test('the rates seeder ignores room types that existed only when the app booted', function (): void {
    hotelRoomTypes(['STD', 'TWN', 'FAM', 'STE']);
    $stale = RatesDocument::initial();
    expect($stale['room_rates'])->not->toBe([]);

    RoomType::query()->delete();
    Property::query()->delete();

    app(ConfigRegistry::class)->register(
        ConfigKind::Rates,
        RateVersion::class,
        RatesDocument::class,
        $stale,
    );

    $this->seed(ConfigSeeder::class);

    expect(RateVersion::query()->firstOrFail()->document['room_rates'])->toBe([]);
});

test('the rates seeder is idempotent', function (): void {
    $this->seed(ConfigSeeder::class);
    $this->seed(ConfigSeeder::class);

    expect(RateVersion::query()->count())->toBe(1);
});
