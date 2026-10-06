<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded business rules document matches the hotel initial document', function (): void {
    $this->seed(ConfigSeeder::class);

    $document = BusinessRulesDocument::initial();

    expect($document)->not->toHaveKey('manifests');
    expect($document['stay']['check_in_time'])->toBe('15:00');
    expect($document['stay']['check_out_time'])->toBe('11:00');
    expect($document['commission']['payable_days_after_check_out'])->toBe(30);

    $row = BusinessRuleVersion::query()->firstOrFail();
    expect($row->version)->toBe(1);
    expect($row->asDocument()->toArray())->toBe($document);
    expect(app(CurrentConfig::class)->businessRules()->toArray())->toBe($document);
});

test('the business rules seeder is idempotent', function (): void {
    $this->seed(ConfigSeeder::class);
    $this->seed(ConfigSeeder::class);

    expect(BusinessRuleVersion::query()->count())->toBe(1);
});
