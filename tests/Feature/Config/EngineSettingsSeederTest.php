<?php

declare(strict_types=1);

use App\Models\EngineSettingsVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\EngineSettingsDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded engine settings omit retired guest and fee keys', function (): void {
    $this->seed(ConfigSeeder::class);

    $document = EngineSettingsDocument::initial();

    expect($document['guests'])->not->toHaveKey('max_per_cabin');
    expect($document['guests'])->not->toHaveKey('max_per_yacht');
    expect($document['guests']['max_per_property'])->toBe(16);
    expect($document['guests']['child_min_age'])->toBe(6);
    expect($document['guests']['child_max_age'])->toBe(17);
    expect($document['fees'])->not->toHaveKey('png');
    expect($document['fees'])->not->toHaveKey('tct_pp');
    expect($document)->not->toHaveKey('charter');

    $row = EngineSettingsVersion::query()->firstOrFail();
    expect($row->version)->toBe(1);
    expect($row->asDocument()->toArray())->toBe($document);
    expect(app(CurrentConfig::class)->engineSettings()->toArray())->toBe($document);
});

test('the engine settings seeder is idempotent', function (): void {
    $this->seed(ConfigSeeder::class);
    $this->seed(ConfigSeeder::class);

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});
