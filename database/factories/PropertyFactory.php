<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('????'));

        return [
            'code' => $code,
            'name' => $code,
            'status' => PropertyStatus::Active,
        ];
    }
}
