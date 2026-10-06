<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;

final class PortalRequestWords
{
    public static function forBooking(Booking $booking): string
    {
        $hours = app(CurrentConfig::class)->businessRules()->sla->responseHours;

        return self::forStatus($booking->status, $hours);
    }

    public static function forStatus(BookingStatus $status, int $responseHours): string
    {
        if ($status === BookingStatus::OnHoldAgency) {
            return 'This request holds the room. The team will answer within '
                .$responseHours
                .' hours. This request is waiting on the commission-cap decision.';
        }

        if ($status === BookingStatus::Requested) {
            return 'This request holds the room. The team will answer within '
                .$responseHours
                .' hours.';
        }

        return 'The team has answered this request.';
    }
}
