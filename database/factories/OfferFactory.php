<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'OF-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT),
            'code' => strtoupper(fake()->unique()->bothify('??##??')),
            'name' => fake()->words(3, true),
            'type' => OfferType::Percent,
            'value' => 10,
            'value_text' => null,
            'channel' => OfferChannel::D2C,
            'partner' => null,
            'booking_from' => null,
            'booking_to' => null,
            'combinable' => false,
            'is_promo_code' => false,
            'badge' => 'TEST OFFER',
            'show_on_card' => true,
            'show_on_calendar' => true,
            'price_line' => 'Test offer line',
            'terms' => 'Test terms.',
            'status' => OfferStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
            'approval_reason' => null,
            'needs_reapproval' => false,
            'first_live_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Pending,
        ]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Live,
            'first_live_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Paused,
            'first_live_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function promo(): static
    {
        return $this->state(fn (): array => [
            'is_promo_code' => true,
            'show_on_card' => false,
            'show_on_calendar' => false,
            'badge' => null,
        ]);
    }

    public function valueAdd(): static
    {
        return $this->state(fn (): array => [
            'type' => OfferType::Value,
            'value' => null,
            'value_text' => 'Complimentary pre-cruise night in Quito',
        ]);
    }
}
