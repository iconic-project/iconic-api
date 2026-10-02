<?php

declare(strict_types=1);

use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
});

test('GET /api/rms/yachts remains a deprecated alias of properties', function (): void {
    $sales = salesExecUser();

    $alias = $this->actingAs($sales)->getJson('/api/rms/yachts');
    $current = $this->actingAs($sales)->getJson('/api/rms/properties');

    $alias->assertOk();
    $current->assertOk();
    expect($alias->json())->toBe($current->json());
});
