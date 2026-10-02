<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Departure>
 */
class DepartureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'DEP-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT),
            'date' => '2028-04-02',
            'property_id' => Property::factory(),
            'itinerary_id' => Itinerary::factory(),
            'status' => DepartureStatus::OnSale,
            'urgency_threshold' => 3,
            'waitlist_enabled' => true,
            'public_note' => null,
            'festive' => false,
        ];
    }
}
