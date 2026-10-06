<?php

declare(strict_types=1);

namespace Tests\Support\Offers;

use App\Enums\ItineraryStatus;
use App\Models\Itinerary;

final class OfferFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'TEST10',
            'name' => 'Test percent',
            'type' => 'PCT',
            'value' => 10,
            'channel' => 'D2C',
            'cabin_types' => ['SUITE'],
            'itinerary_codes' => ['WEST'],
            'combinable' => false,
            'is_promo_code' => false,
            'badge' => 'TEST',
            'show_on_card' => true,
            'show_on_calendar' => true,
            'price_line' => 'Test 10%',
            'terms' => 'Terms.',
        ], $overrides);
    }

    public static function west(): Itinerary
    {
        return Itinerary::query()->where('code', 'WEST')->first()
            ?? Itinerary::factory()->create([
                'code' => 'WEST',
                'name' => 'Western Realm',
                'status' => ItineraryStatus::Published,
                'festive' => false,
            ]);
    }

    public static function north(): Itinerary
    {
        return Itinerary::query()->where('code', 'NORTH')->first()
            ?? Itinerary::factory()->create([
                'code' => 'NORTH',
                'name' => 'Northern Passage',
                'status' => ItineraryStatus::Published,
                'festive' => false,
            ]);
    }

    public static function festive(): Itinerary
    {
        return Itinerary::query()->where('code', 'FEST')->first()
            ?? Itinerary::factory()->create([
                'code' => 'FEST',
                'name' => 'Festive Expeditions',
                'status' => ItineraryStatus::Published,
                'festive' => true,
            ]);
    }
}
