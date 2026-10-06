<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckoutSessionStatus;
use App\Models\CheckoutSession;
use App\Support\IpHash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutSession>
 */
class CheckoutSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => CheckoutSession::hashToken(bin2hex(random_bytes(32))),
            'check_in' => '2026-06-15',
            'check_out' => '2026-06-22',
            'cabins' => [
                ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
            ],
            'status' => CheckoutSessionStatus::Holding,
            'expires_at' => now()->addMinutes(20),
            'extended' => false,
            'ip_hash' => IpHash::of('127.0.0.1'),
            'path' => null,
            'stripe_checkout_session_id' => null,
            'stripe_expires_at' => null,
        ];
    }
}
