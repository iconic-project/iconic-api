<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'GRP-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => fake()->lastName().' group',
            'coordinator_contact_id' => Contact::factory(),
        ];
    }
}
