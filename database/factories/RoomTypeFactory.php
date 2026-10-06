<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => fake()->unique()->lexify('TYPE????'),
            'name' => 'Standard',
            'base_occupancy' => 2,
            'max_occupancy' => 2,
            'max_adults' => 2,
            'max_children' => 0,
            'waitlist_enabled' => true,
            'sort' => 0,
            'status' => RoomTypeStatus::Active,
        ];
    }
}
