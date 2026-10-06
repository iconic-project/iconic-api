<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Bookability;
use App\Services\Inventory\NightAvailability;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Content\Completeness;
use App\Support\Stays\StayDates;
use Illuminate\Validation\ValidationException;

/**
 * One room with this party, and whether `rooms` of that type can be held.
 * rooms_left never exceeds the low-availability threshold plus one.
 */
final class EngineStayAvailability
{
    public function __construct(
        private readonly NightAvailability $availability,
        private readonly StayQuoter $quoter,
        private readonly SeasonNightly $prices,
        private readonly CurrentConfig $config,
        private readonly EnginePropertyFeed $properties,
    ) {}

    /**
     * @param  list<int>  $childAges
     * @return array{check_in: string, check_out: string, adults: int, child_ages: list<int>, rooms: int, room_types: list<array<string, mixed>>}
     */
    public function search(string $checkIn, string $checkOut, int $adults, array $childAges, int $rooms): array
    {
        $this->guardRooms($rooms);
        $stay = StayDates::of($checkIn, $checkOut);
        $this->guardNights($stay);

        $property = $this->properties->property();
        $property->loadMissing('roomTypes');
        $rows = [];

        foreach ($property->roomTypes as $type) {
            if (! Completeness::engineVisible($type)) {
                continue;
            }

            $rows[] = $this->type($type, $stay, $adults, $childAges, $rooms);
        }

        return [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults,
            'child_ages' => $childAges,
            'rooms' => $rooms,
            'room_types' => $rows,
        ];
    }

    /**
     * @param  list<int>  $childAges
     * @return array<string, mixed>
     */
    private function type(RoomType $type, StayDates $stay, int $adults, array $childAges, int $rooms): array
    {
        $reasons = [];

        if (! $this->fits($type, $adults, $childAges)) {
            $reasons[] = 'OVER_OCCUPANCY';
        }

        $booked = $this->availability->canBook($type, $stay, $rooms);

        if ($reasons === []) {
            $reasons = $booked->reasons;
        }

        $quotes = [];

        if ($reasons === []) {
            $quotes = $this->quotes($type, $stay, $adults, $childAges);

            if ($quotes === []) {
                $reasons[] = 'NO_RATE';
            }
        }

        $reasons = Bookability::ordered($reasons);

        return [
            'code' => $type->code,
            'name' => $type->name,
            'bookable' => $reasons === [],
            'reasons' => $reasons,
            'rooms_left' => $this->roomsLeft($booked),
            'waitlist_enabled' => (bool) $type->waitlist_enabled,
            'quotes' => $quotes,
        ];
    }

    /**
     * @param  list<int>  $childAges
     * @return list<array<string, mixed>>
     */
    private function quotes(RoomType $type, StayDates $stay, int $adults, array $childAges): array
    {
        $quotes = [];
        $version = $this->prices->ratesVersionId();

        foreach ($this->prices->plans() as $plan) {
            $result = $this->quoter->quote($type, new StayQuoteInput(
                $stay,
                $type->code,
                $adults,
                $childAges,
                $plan->code,
                null,
                $version,
                false,
            ));

            if (! $result instanceof StayReservationQuote) {
                continue;
            }

            $quote = $result->quote->toArray();
            $quotes[] = [
                'rate_plan' => $plan->code,
                'night_lines' => $quote['night_lines'],
                'lines' => $quote['lines'],
                'tax_lines' => $quote['tax_lines'],
                'total' => $quote['total'],
                'deposit_pct' => $quote['deposit_pct'],
                'deposit' => $quote['deposit'],
                'total_including_charged_taxes' => $quote['total_including_charged_taxes'],
            ];
        }

        return $quotes;
    }

    /**
     * @param  list<int>  $childAges
     */
    private function fits(RoomType $type, int $adults, array $childAges): bool
    {
        $children = count($childAges);

        if ($adults > $type->max_adults || $children > $type->max_children || $adults + $children > $type->max_occupancy) {
            return false;
        }

        $guests = $this->config->engineSettings()->guests;

        foreach ($childAges as $age) {
            if ($age < $guests->childMinAge || $age > $guests->childMaxAge) {
                return false;
            }
        }

        return true;
    }

    private function roomsLeft(Bookability $booked): int
    {
        $free = null;

        foreach ($booked->nights as $night) {
            $free = $free === null ? $night['free'] : min($free, $night['free']);
        }

        $cap = $this->config->engineSettings()->availability->lowAvailabilityThreshold + 1;

        return min($free ?? 0, $cap);
    }

    private function guardRooms(int $rooms): void
    {
        $max = $this->config->businessRules()->stay->maxRoomsPerBooking;

        if ($rooms > $max) {
            throw ValidationException::withMessages([
                'rooms' => ['A booking can take at most '.$max.' rooms.'],
            ]);
        }
    }

    private function guardNights(StayDates $stay): void
    {
        if ($stay->nights() > NightAvailability::MAX_NIGHTS) {
            throw ValidationException::withMessages([
                'check_out' => ['A stay cannot exceed '.NightAvailability::MAX_NIGHTS.' nights.'],
            ]);
        }
    }
}
