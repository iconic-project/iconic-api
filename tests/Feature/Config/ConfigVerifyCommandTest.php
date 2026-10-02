<?php

declare(strict_types=1);

use App\Models\RateVersion;
use App\Support\Config\Documents\RatesDocument;
use Database\Seeders\ConfigSeeder;

test('iconic config-verify accepts the seeded current documents', function (): void {
    $this->seed(ConfigSeeder::class);

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('rates v1: valid')
        ->expectsOutputToContain('business_rules v1: valid')
        ->expectsOutputToContain('engine_settings v1: valid')
        ->expectsOutputToContain('extras v1: valid');
});

test('iconic config-verify fails naming kind, version and path when a required field is missing', function (): void {
    $this->seed(ConfigSeeder::class);

    $document = RatesDocument::initial();
    unset($document['terms']['cabin_deposit_pct']);

    RateVersion::query()->create([
        'version' => 2,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'TEST-MISSING-FIELD',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('rates v2: terms.cabin_deposit_pct');
});

test('iconic config-verify fails when a kind has no published version', function (): void {
    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('rates: No published rates — run the seeders')
        ->expectsOutputToContain('business_rules: No published business rules — run the seeders')
        ->expectsOutputToContain('engine_settings: No published engine settings — run the seeders')
        ->expectsOutputToContain('extras: No published extras — run the seeders');
});
