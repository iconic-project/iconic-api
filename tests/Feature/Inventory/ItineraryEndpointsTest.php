<?php

declare(strict_types=1);

use App\Enums\ItineraryStatus;
use App\Models\Itinerary;
use App\Support\Itineraries\Gradients;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('lucia can view itineraries and cannot write', function (): void {
    $itinerary = Itinerary::factory()->create(['code' => 'WEST']);
    $lucia = salesExecUser();

    $this->actingAs($lucia)
        ->getJson('/api/rms/itineraries')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'WEST');

    $this->actingAs($lucia)
        ->getJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertOk();

    $this->actingAs($lucia)
        ->getJson('/api/rms/itineraries/defaults')
        ->assertOk();

    $this->actingAs($lucia)
        ->postJson('/api/rms/itineraries', ['code' => 'SOUTH', 'name' => 'South'])
        ->assertForbidden();

    $this->actingAs($lucia)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($lucia)
        ->deleteJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertForbidden();
});

test('an admin cannot create, update or delete an itinerary', function (): void {
    $itinerary = Itinerary::factory()->create(['code' => 'WEST']);
    $admin = adminUser();

    $this->actingAs($admin)
        ->postJson('/api/rms/itineraries', [
            'code' => 'south',
            'name' => 'Southern Isles',
        ])
        ->assertForbidden();

    expect(Itinerary::query()->where('code', 'SOUTH')->exists())->toBeFalse();

    $this->actingAs($admin)
        ->postJson('/api/rms/itineraries', [
            'code' => 'SOUTH',
            'name' => 'Southern Isles',
            'hero_alt' => '',
            'card_description' => '',
            'long_description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'slug' => '',
            'fallback_gradient' => Gradients::DEFAULT_KEY,
            'highlights' => [],
            'day_plan' => [],
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['code' => 'EAST', 'name' => 'Renamed'])
        ->assertForbidden();

    expect($itinerary->fresh()?->code)->toBe('WEST');
    expect($itinerary->fresh()?->name)->not->toBe('Renamed');

    $this->actingAs($admin)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['status' => 'PUBLISHED'])
        ->assertForbidden();

    expect($itinerary->fresh()?->status)->toBe(ItineraryStatus::Draft);

    $this->actingAs($admin)
        ->deleteJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertForbidden();

    expect(Itinerary::query()->whereKey($itinerary->id)->exists())->toBeTrue();
});

test('defaults match mkItin', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->getJson('/api/rms/itineraries/defaults')
        ->assertOk()
        ->assertJsonPath('status', 'DRAFT')
        ->assertJsonPath('days', 8)
        ->assertJsonPath('nights', 7)
        ->assertJsonPath('sort_order', 9)
        ->assertJsonPath('embark', 'San Cristóbal (SCY)')
        ->assertJsonPath('facts.0.0', 'Accommodation')
        ->assertJsonPath('fallback_gradient', Gradients::ALL[Gradients::DEFAULT_KEY])
        ->assertJsonPath('fallback_gradient_key', Gradients::DEFAULT_KEY)
        ->assertJsonPath('gradients', Gradients::catalog());
});

test('itinerary rows expose fallback_gradient_key alongside the css', function (): void {
    $itinerary = Itinerary::factory()->create([
        'code' => 'NORTH',
        'fallback_gradient' => 'Northern (forest)',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->getJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertOk()
        ->assertJsonPath('fallback_gradient', Gradients::css('Northern (forest)'))
        ->assertJsonPath('fallback_gradient_key', 'Northern (forest)');

    $this->actingAs($mateo)
        ->getJson('/api/rms/itineraries')
        ->assertOk()
        ->assertJsonPath('data.0.fallback_gradient_key', 'Northern (forest)')
        ->assertJsonPath('data.0.fallback_gradient', Gradients::css('Northern (forest)'));
});

test('code validation still runs before the read-only denial', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/itineraries', ['code' => 'S'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    $this->actingAs($mateo)
        ->postJson('/api/rms/itineraries', ['code' => 'SOUTH-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('completeness lists missing fields in prototype order', function (): void {
    $itinerary = Itinerary::factory()->create([
        'name' => '',
        'card_description' => '',
        'day_plan' => [],
        'highlights' => [],
        'long_description' => '',
        'included' => [],
        'excluded' => [],
        'faqs' => [],
        'slug' => null,
        'meta_title' => '',
        'meta_description' => '',
        'hero_image_path' => null,
    ]);
    $mateo = managerUser();

    $missing = $this->actingAs($mateo)
        ->getJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertOk()
        ->json('completeness.missing');

    expect($missing)->toBe([
        'name',
        'card description',
        'day-by-day plan',
        'hero photo',
        'highlights',
        'long description',
        'includes',
        'excludes',
        'FAQs',
        'URL slug',
        'SEO title',
        'SEO description',
    ]);
    expect($this->actingAs($mateo)->getJson("/api/rms/itineraries/{$itinerary->id}")->json('completeness.blocking'))
        ->toBe(['name', 'card description', 'day-by-day plan']);
    expect($this->actingAs($mateo)->getJson("/api/rms/itineraries/{$itinerary->id}")->json('completeness.pct'))
        ->toBe((int) round((13 - 12) / 13 * 100));
});

test('a publish-shaped write is forbidden', function (): void {
    $admin = adminUser();

    $this->actingAs($admin)
        ->postJson('/api/rms/itineraries', [
            'code' => 'SOUTH',
            'name' => 'Southern Isles',
            'hero_alt' => '',
            'card_description' => '',
            'long_description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'slug' => '',
            'fallback_gradient' => Gradients::DEFAULT_KEY,
            'highlights' => [],
            'day_plan' => [],
        ])
        ->assertForbidden();

    expect(Itinerary::query()->where('code', 'SOUTH')->exists())->toBeFalse();
});
