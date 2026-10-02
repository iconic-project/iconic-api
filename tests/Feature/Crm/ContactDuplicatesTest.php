<?php

declare(strict_types=1);

use App\Models\Contact;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('duplicates lists same phone and same normalised name plus country', function (): void {
    $phoneA = Contact::factory()->create([
        'name' => 'Phone One',
        'email' => 'phone-a@iconic.test',
        'phone' => '6502530000',
        'country' => 'US',
        'phone_e164' => '+16502530000',
    ]);
    $phoneB = Contact::factory()->create([
        'name' => 'Phone Two',
        'email' => 'phone-b@iconic.test',
        'phone' => '+1 650 253 0000',
        'country' => 'US',
        'phone_e164' => '+16502530000',
    ]);
    $nameA = Contact::factory()->create([
        'name' => '  Ada   Lovelace ',
        'email' => 'ada-a@iconic.test',
        'country' => 'EC',
    ]);
    Contact::factory()->create([
        'name' => 'ada lovelace',
        'email' => 'ada-b@iconic.test',
        'country' => 'EC',
    ]);
    Contact::factory()->create([
        'name' => 'Unique Person',
        'email' => 'unique@iconic.test',
        'country' => 'US',
    ]);

    $response = $this->actingAs(managerUser())
        ->getJson('/api/crm/contacts/duplicates')
        ->assertOk();

    assertNoSensitiveFields($response);

    $pairs = $response->json('data');
    expect($pairs)->toHaveCount(2);

    $phonePair = collect($pairs)->first(fn (array $pair): bool => in_array('phone', $pair['reasons'], true));
    expect($phonePair['a']['id'])->toBe($phoneA->id);
    expect($phonePair['b']['id'])->toBe($phoneB->id);

    $namePair = collect($pairs)->first(fn (array $pair): bool => in_array('name_country', $pair['reasons'], true));
    expect($namePair['a']['id'])->toBe($nameA->id);
});

test('merged contacts are not listed as duplicates', function (): void {
    $survivor = Contact::factory()->create([
        'email' => 'surv-dup@iconic.test',
        'phone_e164' => '+16502530000',
        'country' => 'US',
        'name' => 'Same Person',
    ]);
    $loser = Contact::factory()->create([
        'email' => 'lose-dup@iconic.test',
        'phone_e164' => '+16502530000',
        'country' => 'US',
        'name' => 'Same Person',
    ]);

    $this->actingAs(managerUser())
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Same guest',
        ])
        ->assertOk();

    $this->actingAs(managerUser())
        ->getJson('/api/crm/contacts/duplicates')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
