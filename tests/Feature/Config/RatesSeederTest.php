<?php

declare(strict_types=1);

use App\Models\RateVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\RatesDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded rates document matches seed-data.json', function (): void {
    $this->seed(ConfigSeeder::class);

    $path = base_path('docs/requirements/examples/seed-data.json');
    /** @var array{rates: array<string, mixed>} $seed */
    $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $rates = $seed['rates'];
    $document = RatesDocument::initial();

    expect($document['years'][0]['suite_pp'])->toBe($rates['base']['SUITE']['2027']);
    expect($document['years'][0]['owner_pp'])->toBe($rates['base']['OWNER']['2027']);
    expect($document['years'][0]['charter_week'])->toBe($rates['base']['CHARTER']['2027']);
    expect($document['years'][1]['suite_pp'])->toBe($rates['base']['SUITE']['2028']);
    expect($document['years'][1]['owner_pp'])->toBe($rates['base']['OWNER']['2028']);
    expect($document['years'][1]['charter_week'])->toBe($rates['base']['CHARTER']['2028']);
    expect($document['years'][2]['suite_pp'])->toBe($rates['base']['SUITE']['2029']);
    expect($document['years'][2]['owner_pp'])->toBe($rates['base']['OWNER']['2029']);
    expect($document['years'][2]['charter_week'])->toBe($rates['base']['CHARTER']['2029']);
    expect($document['terms']['cabin_deposit_pct'])->toBe($rates['terms']['cabinDep']);
    expect($document['terms']['cabin_balance_days'])->toBe($rates['terms']['cabinBalDays']);
    expect($document['terms']['charter_deposit_pct'])->toBe($rates['terms']['charterDep']);
    expect($document['terms']['charter_deposit_business_days'])->toBe($rates['terms']['charterDepDays']);
    expect($document['terms']['charter_balance_days'])->toBe($rates['terms']['charterBalDays']);
    expect($document['rules']['single_supplement_pct'])->toBe($rates['rules']['single']);
    expect($document['rules']['triple_discount_pct'])->toBe($rates['rules']['triple']);
    expect($document['rules']['child_discount_pct'])->toBe($rates['rules']['child']);
    expect($document['rules']['child_discounts_per_adult'])->toBe($rates['rules']['childPerAdult']);
    expect($document['rules']['child_discounts_per_cabin'])->toBe($rates['rules']['childPerCabin']);
    expect($document['rules']['back_to_back_pct'])->toBe($rates['rules']['b2b']);
    expect($document['rules']['festive_supplement_pp'])->toBe($rates['rules']['festivePax']);
    expect($document['rules']['festive_supplement_charter'])->toBe($rates['rules']['festiveCharter']);
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

test('the rates seeder is idempotent', function (): void {
    $this->seed(ConfigSeeder::class);
    $this->seed(ConfigSeeder::class);

    expect(RateVersion::query()->count())->toBe(1);
});
