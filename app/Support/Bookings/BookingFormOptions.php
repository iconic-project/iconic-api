<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\AgencyStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\ChannelOfOriginGroup;
use App\Enums\MainChannel;
use App\Enums\PreferredChannel;
use App\Enums\RoomTypeStatus;
use App\Models\Agency;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Restrictions;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BookingFormOptions
{
    /**
     * @return array{
     *     main: list<array{value: string, label: string, trade: bool}>,
     *     origin: list<array{group: string, options: list<array{value: string, label: string}>}>,
     *     preferred: list<array{value: string, label: string}>,
     *     guests: array{child_min_age: int, child_max_age: int, max_per_cabin: int},
     *     commission: array{cap_pct: int, default_pct: int},
     *     payments: array{wire_window_hours: int},
     *     agencies: list<array{id: int, reference: string, name: string, network: string|null, commission_pct: int}>,
     *     room_types: list<array{id: int, code: string, name: string, property_id: int, base_occupancy: int, max_occupancy: int, max_adults: int, max_children: int, restrictions: list<string>}>,
     *     rate_plans: list<array{code: string, name: string, default: bool, deposit_pct: int, balance_days: int, refundable: bool}>,
     *     stay: array{min_nights: int, max_nights: int, max_rooms_per_booking: int, booking_horizon_days: int}
     * }
     */
    public static function fromConfig(CurrentConfig $config, ?StayDates $stay = null): array
    {
        $grouped = [];

        foreach (ChannelOfOrigin::cases() as $origin) {
            $grouped[$origin->group()->value][] = [
                'value' => $origin->value,
                'label' => $origin->label(),
            ];
        }

        $origin = [];

        foreach (ChannelOfOriginGroup::cases() as $group) {
            $origin[] = [
                'group' => $group->value,
                'options' => $grouped[$group->value] ?? [],
            ];
        }

        $guests = $config->engineSettings()->guests;
        $rules = $config->businessRules();
        $commission = $rules->commission;

        return [
            'main' => array_map(
                fn (MainChannel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                    'trade' => $channel->isTrade(),
                ],
                MainChannel::cases(),
            ),
            'origin' => $origin,
            'preferred' => array_map(
                fn (PreferredChannel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                ],
                PreferredChannel::cases(),
            ),
            'guests' => [
                'child_min_age' => $guests->childMinAge,
                'child_max_age' => $guests->childMaxAge,
                'max_per_cabin' => $guests->maxPerCabin,
            ],
            'commission' => [
                'cap_pct' => $commission->capPct,
                'default_pct' => $commission->defaultPct,
            ],
            'payments' => [
                'wire_window_hours' => $rules->payments->wireWindowHours,
            ],
            'agencies' => Agency::query()
                ->where('status', AgencyStatus::Approved)
                ->orderBy('name')
                ->get()
                ->map(fn (Agency $agency): array => [
                    'id' => $agency->id,
                    'reference' => $agency->reference,
                    'name' => $agency->name,
                    'network' => $agency->network,
                    'commission_pct' => $agency->commission_pct,
                ])
                ->values()
                ->all(),
            'room_types' => self::roomTypes($stay),
            'rate_plans' => array_map(
                fn (RatePlan $plan): array => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'default' => $plan->isDefault,
                    'deposit_pct' => $plan->depositPct,
                    'balance_days' => $plan->balanceDays,
                    'refundable' => $plan->refundable,
                ],
                $config->rates()->ratePlans,
            ),
            'stay' => [
                'min_nights' => $rules->stay->minNights,
                'max_nights' => $rules->stay->maxNights,
                'max_rooms_per_booking' => $rules->stay->maxRoomsPerBooking,
                'booking_horizon_days' => $rules->stay->bookingHorizonDays,
            ],
        ];
    }

    public static function stayFromQuery(Request $request): ?StayDates
    {
        $checkIn = $request->query('check_in');
        $checkOut = $request->query('check_out');
        $hasIn = is_string($checkIn) && $checkIn !== '';
        $hasOut = is_string($checkOut) && $checkOut !== '';

        if (! $hasIn && ! $hasOut) {
            return null;
        }

        $validator = validator([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ], [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $stay = StayDates::of((string) $checkIn, (string) $checkOut);
        $maxNights = app(StayClock::class)->maxNights();

        if ($stay->nights() > $maxNights) {
            throw ValidationException::withMessages([
                'check_out' => ['A stay cannot be longer than '.$maxNights.' nights.'],
            ]);
        }

        return $stay;
    }

    /**
     * @return list<array{id: int, code: string, name: string, property_id: int, base_occupancy: int, max_occupancy: int, max_adults: int, max_children: int, restrictions: list<string>}>
     */
    private static function roomTypes(?StayDates $stay): array
    {
        $restrictions = app(Restrictions::class);

        return RoomType::query()
            ->where('status', RoomTypeStatus::Active)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (RoomType $type) use ($stay, $restrictions): array {
                return [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'property_id' => $type->property_id,
                    'base_occupancy' => $type->base_occupancy,
                    'max_occupancy' => $type->max_occupancy,
                    'max_adults' => $type->max_adults,
                    'max_children' => $type->max_children,
                    'restrictions' => $stay instanceof StayDates
                        ? $restrictions->evaluate($type, $stay)->reasons
                        : [],
                ];
            })
            ->values()
            ->all();
    }
}
