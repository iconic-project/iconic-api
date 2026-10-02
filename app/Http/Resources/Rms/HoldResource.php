<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\HoldRule;
use App\Enums\HoldType;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\HoldRuleText;
use App\Support\BusinessHours;
use App\Support\Iso;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class HoldResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     type: string,
     *     client: string,
     *     departure: array{date: string, property: array{code: string, name: string}},
     *     cabin: string,
     *     expires_at: string|null,
     *     remaining_business_minutes: int,
     *     rule: string,
     *     reference: string|null,
     *     booking_id: int|null
     * }
     */
    public function toArray(Request $request): array
    {
        $payload = self::fromBooking($this->resource);

        return [
            'type' => $payload['type'],
            'client' => $payload['client'],
            'departure' => $payload['departure'],
            'cabin' => $payload['cabin'],
            'expires_at' => Iso::utc($payload['expires_at']),
            'remaining_business_minutes' => $payload['remaining_business_minutes'],
            'rule' => $payload['rule'],
            'reference' => $payload['reference'],
            'booking_id' => $payload['booking_id'],
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     client: string,
     *     departure: array{date: string, property: array{code: string, name: string}},
     *     cabin: string,
     *     expires_at: DateTimeInterface|null,
     *     remaining_business_minutes: int,
     *     rule: string,
     *     reference: string|null,
     *     booking_id: int|null
     * }
     */
    public static function fromBooking(Booking $booking): array
    {
        $booking->loadMissing(['departure.property', 'cabin', 'contact', 'bookingRequest', 'claims']);
        $rules = app(CurrentConfig::class)->businessRules();
        $hours = BusinessHours::fromDocument($rules);
        $hold = $booking->claims
            ->first(fn ($claim): bool => $claim->released_at === null);

        $expiresAt = $hold?->expires_at;
        $request = $booking->bookingRequest;
        $rule = $request instanceof BookingRequest ? $request->hold_rule : HoldRule::LongLead;
        $holdType = $hold?->hold_type;

        return [
            'type' => $holdType instanceof HoldType ? $holdType->value : 'REQUEST',
            'client' => $booking->contact->name,
            'departure' => [
                'date' => $booking->departure->date->toDateString(),
                'property' => [
                    'code' => $booking->departure->property->code,
                    'name' => $booking->departure->property->name,
                ],
            ],
            'cabin' => $booking->cabinLabel(),
            'expires_at' => $expiresAt,
            'remaining_business_minutes' => $expiresAt instanceof DateTimeInterface
                ? $hours->remainingBusinessMinutes(now(), $expiresAt)
                : 0,
            'rule' => HoldRuleText::tec004($rule, $rules),
            'reference' => $booking->displayReference(),
            'booking_id' => $booking->id,
        ];
    }
}
