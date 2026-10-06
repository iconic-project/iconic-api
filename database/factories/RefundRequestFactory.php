<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundRequestStatus;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundRequest>
 */
class RefundRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = BusinessTime::now();

        return [
            'booking_id' => Booking::factory(),
            'cancelled_at' => $now,
            'days_before_arrival' => 484,
            'band_min_days' => 120,
            'penalty_pct' => 5,
            'penalty_amount' => 1330,
            'paid_at_cancellation' => 2660,
            'refund_due' => 1330,
            'status' => RefundRequestStatus::Pending,
            'due_by' => $now->addWeekdays(15),
            'decided_at' => null,
            'decided_by' => null,
            'decision_reason' => null,
            'executed_payment_id' => null,
        ];
    }
}
