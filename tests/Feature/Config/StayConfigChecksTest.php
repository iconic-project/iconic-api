<?php

declare(strict_types=1);

use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Support\Config\Documents\RatesDocument;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

test('the rates page warns when a restriction sits on a night with no rate', function (): void {
    CarbonImmutable::setTestNow('2026-02-01 12:00:00');
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);

    $property = Property::factory()->create();
    $type = RoomType::query()->create([
        'property_id' => $property->id,
        'code' => 'STD',
        'name' => 'Standard Double',
        'slug' => 'std-restriction',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 0,
        'sort' => 1,
        'status' => RoomTypeStatus::Active,
    ]);

    StayRestriction::query()->create([
        'property_id' => $property->id,
        'night' => '2026-10-15',
        'stop_sell' => true,
    ]);
    StayRestriction::query()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'night' => '2026-02-02',
        'stop_sell' => true,
    ]);

    $document = RatesDocument::initial();
    $document['room_rates'] = [];

    $response = $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/validate', [
            'document' => $document,
        ]);

    $response->assertOk();
    $messages = collect($response->json('warnings'))->pluck('message')->all();

    expect($messages)->toContain('A restriction on 2026-10-15 sits on a night with no rate.');
    expect($messages)->toContain('A restriction on 2026-02-02 for STD sits on a night with no rate.');
    expect($messages)->toContain('Standard Double (STD) has no rate in Low (LOW).');
});
