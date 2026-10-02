<?php

declare(strict_types=1);

namespace Tests\Support\Bookings;

use App\Enums\ItineraryStatus;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;

final class ReservationFixtures
{
    public static function anamaraDeparture(string $date = '2027-11-07', bool $festive = false): Departure
    {
        $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

        $existing = Departure::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $date)
            ->first();

        if ($existing instanceof Departure) {
            if ($festive && ! $existing->festive) {
                $existing->update(['festive' => true]);
            }

            return $existing->load(['property.cabins']);
        }

        return Departure::factory()->create([
            'property_id' => $property->id,
            'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
            'date' => $date,
            'festive' => $festive,
        ]);
    }

    /**
     * @param  list<array{cabin_code?: string, adults: int, children: int}>  $cabins
     * @return array<string, mixed>
     */
    public static function quotePayload(Departure $departure, array $cabins, string $type = 'CABIN', bool $backToBack = false): array
    {
        return [
            'departure_id' => $departure->id,
            'type' => $type,
            'back_to_back' => $backToBack,
            'cabins' => $cabins,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function createPayload(Departure $departure, array $overrides = []): array
    {
        $payload = [
            'departure_id' => $departure->id,
            'type' => 'CABIN',
            'back_to_back' => false,
            'cabins' => [
                ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
            ],
            'client' => [
                'name' => 'Test Guest',
                'email' => 'guest-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'group' => null,
        ];

        if (isset($overrides['client']) && is_array($overrides['client'])) {
            $overrides['client'] = array_merge($payload['client'], $overrides['client']);
        }

        return array_merge($payload, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function requestPayload(Departure $departure, array $overrides = []): array
    {
        $payload = self::createPayload($departure, $overrides);

        unset($payload['group']);

        return array_merge([
            'preferred_channel' => 'EMAIL',
            'travel_advisor' => false,
            'notes' => null,
        ], $payload);
    }
}
