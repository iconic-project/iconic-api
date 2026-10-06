<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'position' => 1,
            'is_lead' => true,
            'first_name' => '',
            'last_name' => '',
            'dob' => null,
            'nationality' => null,
            'ecuador_resident' => false,
            'passport_no' => null,
            'passport_expiry' => null,
            'email' => null,
            'insurance_declared' => false,
            'medical_note' => null,
            'dietary_note' => null,
            'accessibility_note' => null,
            'guardian_name' => null,
            'guardian_relationship' => null,
            'guardian_consented_at' => null,
            'guardian_recorded_by' => null,
            'png_fee' => null,
        ];
    }
}
