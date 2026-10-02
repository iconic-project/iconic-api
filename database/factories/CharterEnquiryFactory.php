<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CharterEnquirySource;
use App\Enums\CharterEnquiryStatus;
use App\Models\CharterEnquiry;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharterEnquiry>
 */
class CharterEnquiryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'preferred_from' => '2027-11-01',
            'preferred_to' => '2027-12-31',
            'departure_id' => null,
            'guests' => 12,
            'contact_id' => Contact::factory(),
            'message' => 'We would like the property for a week.',
            'source' => CharterEnquirySource::Engine,
            'status' => CharterEnquiryStatus::New,
        ];
    }
}
