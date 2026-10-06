<?php

declare(strict_types=1);

use App\Enums\OfferStatus;
use App\Models\ChangeHistory;
use App\Models\Offer;
use Database\Seeders\RolesSeeder;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('saving a percent offer goes pending and needs a director', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->assertJsonPath('status', OfferStatus::Pending->value)
        ->json('id');

    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Looks good'])
        ->assertForbidden();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Approved for opening season'])
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Live->value)
        ->assertJsonPath('approval_reason', 'Approved for opening season');

    expect(ChangeHistory::query()->where('event', 'offer.approved')->count())->toBe(1);
    expect(Offer::query()->findOrFail($id)->first_live_at)->not->toBeNull();
});

test('editing a live value returns the offer to pending and editing the badge does not', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->json('id');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Go live'])
        ->assertOk();

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['badge' => 'NEW BADGE'])
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Live->value)
        ->assertJsonPath('badge', 'NEW BADGE');

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['value' => 12])
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Pending->value)
        ->assertJsonPath('value', 12);

    expect(ChangeHistory::query()->where('event', 'offer.submitted')->count())->toBe(1);
});

test('flipping is_promo_code on a live price-affecting offer returns it to pending', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->json('id');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Go live'])
        ->assertOk();

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['is_promo_code' => true])
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Pending->value)
        ->assertJsonPath('is_promo_code', true);
});

test('flipping is_promo_code while paused needs approval again on resume', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->json('id');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Go live'])
        ->assertOk();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers/'.$id.'/pause')
        ->assertOk()
        ->assertJsonPath('stored_status', OfferStatus::Paused->value);

    $this->actingAs(managerUser())
        ->patchJson('/api/rms/offers/'.$id, ['is_promo_code' => true])
        ->assertOk()
        ->assertJsonPath('stored_status', OfferStatus::Paused->value);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers/'.$id.'/resume')
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Pending->value);

    expect(ChangeHistory::query()->where('event', 'offer.resumed')->count())->toBe(1);
});

test('resuming an unchanged paused offer goes live', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->json('id');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/approve', ['reason' => 'Go live'])
        ->assertOk();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers/'.$id.'/pause')
        ->assertOk();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers/'.$id.'/resume')
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Live->value);
});

test('reject returns a pending offer to draft with the reason', function (): void {
    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertCreated()
        ->json('id');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/offers/'.$id.'/reject', ['reason' => 'Too generous'])
        ->assertOk()
        ->assertJsonPath('status', OfferStatus::Draft->value)
        ->assertJsonPath('approval_reason', 'Too generous');

    expect(ChangeHistory::query()->where('event', 'offer.rejected')->count())->toBe(1);
});

test('a value-add offer goes live without director approval', function (): void {
    $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload([
            'code' => 'NIGHT',
            'type' => 'VALUE',
            'value' => null,
            'value_text' => 'Complimentary night before arrival',
        ]))
        ->assertCreated()
        ->assertJsonPath('status', OfferStatus::Live->value)
        ->assertJsonPath('benefit_label', 'Complimentary night before arrival');
});
