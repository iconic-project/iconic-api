<?php

declare(strict_types=1);

use App\Models\EngineSettingsVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\EngineSettingsDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded engine settings document matches seed-data.json plus the FIN-004 png table', function (): void {
    $this->seed(ConfigSeeder::class);

    $path = base_path('docs/requirements/examples/seed-data.json');
    /** @var array{engine_settings: array<string, mixed>} $seed */
    $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $source = $seed['engine_settings'];
    $document = EngineSettingsDocument::initial();

    expect($document['guests']['max_per_cabin'])->toBe($source['maxCabin']);
    expect($document['guests']['max_per_property'])->toBe($source['maxProperty']);
    expect($document['guests']['child_min_age'])->toBe($source['childMin']);
    expect($document['guests']['child_max_age'])->toBe($source['childMax']);
    expect($document['guests']['adult_required_with_children'])->toBe($source['adultWithChild']);
    expect($document['guests']['under_age_message'])->toBe($source['underMsg']);
    expect($document['calendar']['default_search_from'])->toBe($source['defFrom']);
    expect($document['calendar']['default_search_to'])->toBe($source['defTo']);
    expect($document['calendar']['default_adults'])->toBe($source['defAdults']);
    expect($document['calendar']['horizon_months'])->toBe($source['horizon']);
    expect($document['copy']['book_now_pay_later'])->toBe($source['noteBNPL']);
    expect($document['copy']['traveling_with_children'])->toBe($source['noteChild']);
    expect($document['copy']['solo_and_triple'])->toBe($source['noteSolo']);
    expect($document['copy']['pay_today'])->toBe($source['payToday']);
    expect($document['copy']['details_note'])->toBe($source['noteDetails']);
    expect($document['copy']['confirmation_steps'])->toBe([
        $source['step1'],
        $source['step2'],
        $source['step3'],
    ]);
    expect($document['copy']['online_deposit_advantage'])->toBe('Online deposit advantage');
    expect($document['copy']['online_deposit_perk'])->toBe('Complimentary spa access aboard');
    expect($document['fees']['tct_pp'])->toBe($source['tct']);
    expect($document['fees']['png']['foreign_over_12'])->toBe($source['pngAd']);
    expect($document['fees']['png']['foreign_12_and_under'])->toBe($source['pngCh']);
    expect($document['fees']['png']['can_adult'])->toBe(100);
    expect($document['fees']['png']['can_minor'])->toBe(30);
    expect($document['fees']['png']['national_or_resident'])->toBe(30);
    expect($document['fees']['png']['exempt_under_age'])->toBe(2);
    expect($document['fees']['show_in_price_panel'])->toBe($source['showFees']);
    expect($document['fees']['footnote'])->toBe($source['feeNote']);
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
