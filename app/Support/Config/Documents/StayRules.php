<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class StayRules
{
    public function __construct(
        public string $checkInTime,
        public string $checkOutTime,
        public string $noShowCutoffTime,
        public int $minNights,
        public int $maxNights,
        public int $maxRoomsPerBooking,
        public bool $checkInRequiresFullPayment,
        public int $bookingHorizonDays,
    ) {}

    /**
     * @return array{
     *     check_in_time: string,
     *     check_out_time: string,
     *     no_show_cutoff_time: string,
     *     min_nights: int,
     *     max_nights: int,
     *     max_rooms_per_booking: int,
     *     check_in_requires_full_payment: bool,
     *     booking_horizon_days: int
     * }
     */
    public function toArray(): array
    {
        return [
            'check_in_time' => $this->checkInTime,
            'check_out_time' => $this->checkOutTime,
            'no_show_cutoff_time' => $this->noShowCutoffTime,
            'min_nights' => $this->minNights,
            'max_nights' => $this->maxNights,
            'max_rooms_per_booking' => $this->maxRoomsPerBooking,
            'check_in_requires_full_payment' => $this->checkInRequiresFullPayment,
            'booking_horizon_days' => $this->bookingHorizonDays,
        ];
    }
}
