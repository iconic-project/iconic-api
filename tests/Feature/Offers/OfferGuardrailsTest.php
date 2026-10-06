<?php

declare(strict_types=1);

use App\Models\ChangeHistory;
use App\Models\Offer;
use Database\Seeders\RolesSeeder;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('an unknown room type is refused', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'applies_to_room_types' => ['NOPE'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['applies_to_room_types']);
});

test('b2b and promo codes never get public surfaces', function (): void {
    $b2b = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'code' => 'B2B10',
            'channel' => 'B2B',
            'show_on_card' => true,
            'show_on_calendar' => true,
        ]))
        ->assertCreated()
        ->json();

    expect($b2b['show_on_card'])->toBeFalse();
    expect($b2b['show_on_calendar'])->toBeFalse();
    expect($b2b['engine_placement'])->toBe('not_public');

    $promo = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'code' => 'PROMO10',
            'is_promo_code' => true,
            'show_on_card' => true,
            'show_on_calendar' => true,
        ]))
        ->assertCreated()
        ->json();

    expect($promo['show_on_card'])->toBeFalse();
    expect($promo['show_on_calendar'])->toBeFalse();
    expect($promo['is_promo_code'])->toBeTrue();
    expect($promo['engine_placement'])->toBe('not_public');
});

test('commission on d2c is refused', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'code' => 'COMM2',
            'type' => 'COMM',
            'value' => 2,
            'channel' => 'D2C',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel']);
});

test('a never-live draft can rename its code', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload(['as_draft' => true]))
        ->assertCreated()
        ->assertJsonPath('status', 'DRAFT')
        ->json('id');

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['code' => 'RENAMED'])
        ->assertOk()
        ->assertJsonPath('code', 'RENAMED');
});

test('the code cannot change after the offer has been live', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'type' => 'VALUE',
            'value_text' => 'Complimentary night',
            'value' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'LIVE')
        ->json('id');

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['code' => 'NEWCODE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect(Offer::query()->findOrFail($id)->code)->toBe('TEST10');
    expect(ChangeHistory::query()->where('event', 'offer.updated')->count())->toBe(0);
});
