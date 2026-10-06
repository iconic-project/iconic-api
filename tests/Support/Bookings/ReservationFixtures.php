<?php

declare(strict_types=1);

namespace Tests\Support\Bookings;

use App\Enums\ConfigKind;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;

final class ReservationFixtures
{
    public static function anamaraDeparture(string $date = '2026-12-21', bool $festive = false): StayAnchor
    {
        $property = Property::query()->where('code', 'ANAMARA')->first();

        if (! $property instanceof Property) {
            $property = Property::factory()->create([
                'code' => 'ANAMARA',
                'name' => 'Anamara',
            ]);
        }

        if (! $property->rooms()->exists()) {
            $type = RoomType::factory()->create([
                'property_id' => $property->id,
                'code' => 'STD',
                'name' => 'Standard',
            ]);

            Room::query()->create([
                'property_id' => $property->id,
                'room_type_id' => $type->id,
                'code' => 'S1',
                'label' => 'Room S1',
                'sort' => 1,
                'status' => RoomStatus::Active,
            ]);
        }

        self::publishRoomRates();

        return new StayAnchor($date, $property->fresh() ?? $property, $festive);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function createPayload(StayAnchor $anchor, array $overrides = []): array
    {
        $checkOut = CarbonImmutable::parse($anchor->date)->addDays(2)->toDateString();
        $roomType = $anchor->property->rooms()->with('roomType')->first()?->roomType?->code ?? 'STD';

        $payload = [
            'check_in' => $anchor->date,
            'check_out' => $checkOut,
            'rooms' => [[
                'room_type' => $roomType,
                'adults' => 2,
                'child_ages' => [],
            ]],
            'client' => [
                'name' => 'Test Guest',
                'email' => 'guest-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'group' => null,
        ];

        if (isset($overrides['client']) && is_array($overrides['client'])) {
            $overrides['client'] = array_merge($payload['client'], $overrides['client']);
        }

        unset($overrides['cabins'], $overrides['type'], $overrides['back_to_back'], $overrides['departure_id']);

        $payload = array_merge($payload, $overrides);

        if (! array_key_exists('expected_total', $payload)) {
            $payload['expected_total'] = self::quotedTotal($payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function quotedTotal(array $payload): int
    {
        $roomTypeCode = 'STD';
        $rooms = $payload['rooms'] ?? null;

        if (is_array($rooms) && isset($rooms[0]) && is_array($rooms[0]) && is_string($rooms[0]['room_type'] ?? null)) {
            $roomTypeCode = $rooms[0]['room_type'];
        }

        $type = RoomType::query()->where('code', $roomTypeCode)->first();

        if (! $type instanceof RoomType || ! is_string($payload['check_in'] ?? null) || ! is_string($payload['check_out'] ?? null)) {
            return 0;
        }

        $quoted = app(StayQuoter::class)->quote($type, new StayQuoteInput(
            StayDates::of($payload['check_in'], $payload['check_out']),
            $roomTypeCode,
            2,
            [],
            'BAR',
        ));

        return $quoted instanceof StayReservationQuote ? $quoted->quote->total : 0;
    }

    private static function publishRoomRates(): void
    {
        $config = app(CurrentConfig::class);

        if ($config->rates()->roomRates !== []) {
            return;
        }

        $version = $config->version(ConfigKind::Rates);

        app(ConfigPublisher::class)->publish(
            ConfigKind::Rates,
            RatesDocument::initial(),
            $version->version,
            'Test fixture: room rates after room types exist',
            null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return array<string, mixed>
     */
    public static function quotePayload(StayAnchor $anchor, array $rooms = [], string $type = 'ROOM', bool $backToBack = false): array
    {
        unset($type, $backToBack);

        $payload = self::createPayload($anchor);

        if ($rooms !== []) {
            $payload['rooms'] = $rooms;
        }

        unset($payload['client'], $payload['channel_of_origin'], $payload['group']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function requestPayload(StayAnchor $anchor, array $overrides = []): array
    {
        $payload = self::createPayload($anchor, $overrides);

        unset($payload['group']);

        return array_merge([
            'preferred_channel' => 'EMAIL',
            'travel_advisor' => false,
            'notes' => null,
        ], $payload);
    }
}
