<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99);

        return [
            'property_id' => Property::factory(),
            'room_type_id' => function (array $attributes): int {
                $propertyId = (int) $attributes['property_id'];
                $existing = RoomType::query()
                    ->where('property_id', $propertyId)
                    ->where('code', 'SUITE')
                    ->first();

                if ($existing instanceof RoomType) {
                    return $existing->id;
                }

                return RoomType::factory()->create([
                    'property_id' => $propertyId,
                    'code' => 'SUITE',
                    'name' => 'Suite',
                ])->id;
            },
            'code' => 'S'.$number,
            'label' => 'Suite '.$number,
            'floor' => null,
            'sort' => $number,
            'status' => RoomStatus::Active,
        ];
    }
}
