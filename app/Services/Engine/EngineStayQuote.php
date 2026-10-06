<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Models\RoomType;
use App\Services\Inventory\NightAvailability;
use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Content\Completeness;
use App\Support\Engine\QuoteToken;
use App\Support\Stays\StayDates;
use Illuminate\Validation\ValidationException;

/**
 * The quote checkout will charge. The token carries the stay, the rooms, the totals and the rates version.
 */
final class EngineStayQuote
{
    public function __construct(
        private readonly NightAvailability $availability,
        private readonly StayQuoter $quoter,
        private readonly SeasonNightly $prices,
        private readonly EnginePropertyFeed $properties,
    ) {}

    /**
     * @param  array{check_in: string, check_out: string, rooms: list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>}  $input
     * @return array<string, mixed>
     */
    public function quote(array $input): array
    {
        $stay = StayDates::of($input['check_in'], $input['check_out']);

        if ($stay->nights() > NightAvailability::MAX_NIGHTS) {
            throw ValidationException::withMessages([
                'check_out' => ['A stay cannot exceed '.NightAvailability::MAX_NIGHTS.' nights.'],
            ]);
        }

        $property = $this->properties->property();
        $property->loadMissing('roomTypes');
        $byCode = [];

        foreach ($property->roomTypes as $type) {
            if (Completeness::engineVisible($type)) {
                $byCode[$type->code] = $type;
            }
        }

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($input['rooms'] as $index => $room) {
            $type = $byCode[$room['room_type']] ?? null;

            if (! $type instanceof RoomType) {
                throw ValidationException::withMessages([
                    'rooms.'.$index.'.room_type' => ['This room type is not on sale.'],
                ]);
            }

            $counts[$type->code] = ($counts[$type->code] ?? 0) + 1;
        }

        foreach ($counts as $code => $count) {
            $type = $byCode[$code];
            $booked = $this->availability->canBook($type, $stay, $count);

            if (! $booked->ok) {
                throw ValidationException::withMessages([
                    'rooms' => $booked->reasons,
                ]);
            }
        }

        $spec = [];

        foreach ($input['rooms'] as $room) {
            $spec[] = [
                'room_type' => $room['room_type'],
                'adults' => $room['adults'],
                'child_ages' => $room['child_ages'],
                'rate_plan' => $room['rate_plan'],
            ];
        }

        $quoted = $this->quoter->quoteRooms($stay, $spec);
        $rooms = [];

        foreach ($quoted->rooms as $index => $row) {
            $result = $row['result'];

            if ($result instanceof GuestsInvalid) {
                throw ValidationException::withMessages([
                    'rooms.'.$index.'.adults' => ['OVER_OCCUPANCY'],
                ]);
            }

            if (! $result instanceof StayReservationQuote) {
                throw ValidationException::withMessages([
                    'rooms.'.$index.'.rate_plan' => ['NO_RATE'],
                ]);
            }

            $quote = $result->quote->toArray();
            $rooms[] = [
                'room_type' => $row['room_type'],
                'rate_plan' => $input['rooms'][$index]['rate_plan'],
                'adults' => $input['rooms'][$index]['adults'],
                'child_ages' => $input['rooms'][$index]['child_ages'],
                'night_lines' => $quote['night_lines'],
                'lines' => $quote['lines'],
                'tax_lines' => $quote['tax_lines'],
                'total' => $quote['total'],
                'deposit_pct' => $quote['deposit_pct'],
                'deposit' => $quote['deposit'],
                'total_including_charged_taxes' => $quote['total_including_charged_taxes'],
                'rates_version_id' => $quote['rates_version_id'],
            ];
        }

        $payload = [
            'check_in' => $input['check_in'],
            'check_out' => $input['check_out'],
            'nights' => $stay->nights(),
            'rooms' => $rooms,
            'total' => $quoted->total,
            'deposit' => $quoted->deposit,
            'total_including_charged_taxes' => $quoted->totalIncludingChargedTaxes,
            'rates_version_id' => $this->prices->ratesVersionId(),
        ];

        $payload['quote_token'] = QuoteToken::issue([
            'check_in' => $payload['check_in'],
            'check_out' => $payload['check_out'],
            'rooms' => array_map(
                fn (array $room): array => [
                    'room_type' => $room['room_type'],
                    'adults' => $room['adults'],
                    'child_ages' => $room['child_ages'],
                    'rate_plan' => $room['rate_plan'],
                ],
                $rooms,
            ),
            'total' => $payload['total'],
            'deposit' => $payload['deposit'],
            'total_including_charged_taxes' => $payload['total_including_charged_taxes'],
            'rates_version_id' => $payload['rates_version_id'],
        ]);

        return $payload;
    }
}
