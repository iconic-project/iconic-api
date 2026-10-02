<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CabinCategory;
use App\Models\Cabin;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cabin>
 */
class CabinFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->numberBetween(1, 8);

        return [
            'property_id' => Property::factory(),
            'code' => 'S'.$number,
            'label' => 'Suite 0'.$number,
            'category' => CabinCategory::Suite,
            'sort' => $number,
        ];
    }
}
