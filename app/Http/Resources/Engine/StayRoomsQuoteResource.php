<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\StayReservationQuote;
use App\Services\Pricing\StayRoomsQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StayRoomsQuote
 */
class StayRoomsQuoteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     check_in: string,
     *     check_out: string,
     *     rooms: list<array{room_type: string, quote: array<string, mixed>|null, errors: list<string>, warnings: list<string>}>,
     *     total: int|null,
     *     deposit: int|null,
     *     total_including_charged_taxes: int|null
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var StayRoomsQuote $quote */
        $quote = $this->resource;

        return [
            'check_in' => $quote->stay->checkIn()->toDateString(),
            'check_out' => $quote->stay->checkOut()->toDateString(),
            'rooms' => array_map(
                fn (array $room): array => $this->room($room['room_type'], $room['result']),
                $quote->rooms,
            ),
            'total' => $quote->total,
            'deposit' => $quote->deposit,
            'total_including_charged_taxes' => $quote->totalIncludingChargedTaxes,
        ];
    }

    /**
     * @return array{room_type: string, quote: array<string, mixed>|null, errors: list<string>, warnings: list<string>}
     */
    private function room(string $code, StayReservationQuote|NoRate|GuestsInvalid $result): array
    {
        if ($result instanceof StayReservationQuote) {
            return [
                'room_type' => $result->roomType->code,
                'quote' => $result->quote->toArray(),
                'errors' => [],
                'warnings' => $result->warnings,
            ];
        }

        if ($result instanceof GuestsInvalid) {
            return [
                'room_type' => $code,
                'quote' => null,
                'errors' => $result->errors,
                'warnings' => [],
            ];
        }

        return [
            'room_type' => $code,
            'quote' => null,
            'errors' => [$result->reason],
            'warnings' => [],
        ];
    }
}
