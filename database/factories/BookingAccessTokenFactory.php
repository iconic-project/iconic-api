<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingAccessTokenPurpose;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingAccessToken>
 */
class BookingAccessTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = bin2hex(random_bytes(32));

        return [
            'booking_id' => Booking::factory(),
            'token_hash' => BookingAccessToken::hashToken($token),
            'purpose' => BookingAccessTokenPurpose::Complete,
            'expires_at' => now()->addYear(),
            'revoked_at' => null,
            'page_url' => rtrim((string) config('iconic.engine_url'), '/').'/complete/'.$token,
        ];
    }
}
