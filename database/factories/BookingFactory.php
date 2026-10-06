<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChannelOfOrigin;
use App\Enums\MainChannel;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\RateVersion;
use App\Models\Room;
use App\Models\User;
use App\Support\Bookings\SoldOn;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'ANK-2026-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 4, '0', STR_PAD_LEFT),
            'request_reference' => null,
            'type' => BookingType::Room,
            'room_id' => Room::factory(),
            'property_id' => function (array $attributes): int {
                return (int) Room::query()->findOrFail($attributes['room_id'])->property_id;
            },
            'room_type_id' => function (array $attributes): int {
                return (int) Room::query()->findOrFail($attributes['room_id'])->room_type_id;
            },
            'check_in' => '2027-01-10',
            'check_out' => '2027-01-17',
            'nights' => 7,
            'contact_id' => Contact::factory(),
            'group_id' => null,
            'owner_id' => User::factory(),
            'status' => BookingStatus::PendingPayment,
            'main_channel' => MainChannel::D2C,
            'channel_of_origin' => ChannelOfOrigin::HotelBookingEngine,
            'adults' => 2,
            'children' => 0,
            'rates_version_id' => fn (): int => $this->currentRatesVersionId(),
            'price_lines' => [
                ['code' => 'base', 'label' => '2 adults', 'amount' => 26600],
            ],
            'total' => 26600,
            'deposit_pct' => 10,
            'balance_days' => 120,
            'promo_code' => null,
            'online_deposit' => false,
            'sold_on' => SoldOn::today(),
            'internal_notes' => null,
            'tct_collected' => false,
            'tct_rate_usd' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Cancelled,
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Released,
        ]);
    }

    private function currentRatesVersionId(): int
    {
        $id = RateVersion::query()->orderByDesc('version')->value('id');

        if (! is_int($id)) {
            throw new RuntimeException('No published rates — seed ConfigSeeder before creating bookings.');
        }

        return $id;
    }
}
