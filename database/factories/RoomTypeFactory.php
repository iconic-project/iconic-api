<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
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
        $max = app(CurrentConfig::class)->engineSettings()->guests->maxPerCabin;

        return [
            'property_id' => Property::factory(),
            'code' => fake()->unique()->lexify('TYPE????'),
            'name' => 'Suite',
            'base_occupancy' => $max,
            'max_occupancy' => $max,
            'max_adults' => $max,
            'max_children' => $max,
            'waitlist_enabled' => true,
            'sort' => 0,
            'status' => RoomTypeStatus::Active,
        ];
    }
}
