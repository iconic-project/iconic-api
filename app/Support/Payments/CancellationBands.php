<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\BookingType;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\CancellationBand;

/**
 * Penalty bands for a booking. A rate plan names its set. A legacy booking
 * with no plan uses STANDARD or CHARTER.
 */
final class CancellationBands
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @return list<CancellationBand>
     */
    public function forBooking(Booking $booking): array
    {
        $rules = $this->config->businessRules();
        $code = $this->setCode($booking);
        $sets = $rules->cancellationSets;

        if (isset($sets[$code]) && $sets[$code] !== []) {
            return $sets[$code];
        }

        if ($booking->type === BookingType::Charter && $rules->charterBands !== []) {
            return $rules->charterBands;
        }

        return $rules->bands;
    }

    public function setCode(Booking $booking): string
    {
        $planCode = $booking->rate_plan_code;

        if (is_string($planCode) && $planCode !== '') {
            foreach ($this->config->rates()->ratePlans as $plan) {
                if ($plan->code === $planCode && $plan->cancellation !== '') {
                    return $plan->cancellation;
                }
            }
        }

        return $booking->type === BookingType::Charter ? 'CHARTER' : 'STANDARD';
    }
}
