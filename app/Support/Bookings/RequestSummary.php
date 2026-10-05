<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\ClaimKind;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\RoomNightClaim;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessHours;
use App\Support\Iso;
use DateTimeInterface;

final class RequestSummary
{
    /**
     * @return array{
     *     preferred_channel: string,
     *     travel_advisor: bool,
     *     notes: string|null,
     *     hold: array{expires_at: string|null, expired: bool, rule: string, remaining_business_minutes: int},
     *     sla: array{due_at: string, remaining_minutes: int, breached: bool}
     * }|null
     */
    public static function for(Booking $booking): ?array
    {
        $booking->loadMissing(['bookingRequest', 'activeClaims']);

        $details = $booking->bookingRequest;

        if (! $details instanceof BookingRequest) {
            return null;
        }

        $expiresAt = self::holdClaim($booking)?->expires_at;
        $dueAt = $details->sla_due_at;
        $remainingSla = (int) now()->diffInMinutes($dueAt, false);
        $expired = $booking->holdExpired();
        $rules = app(CurrentConfig::class)->businessRules();
        $hours = BusinessHours::fromDocument($rules);
        $remainingHold = ($expiresAt instanceof DateTimeInterface && ! $expired)
            ? $hours->remainingBusinessMinutes(now(), $expiresAt)
            : 0;

        return [
            'preferred_channel' => $details->preferred_channel->value,
            'travel_advisor' => $details->travel_advisor,
            'notes' => $details->notes,
            'hold' => [
                'expires_at' => Iso::utc($expiresAt instanceof DateTimeInterface ? $expiresAt : null),
                'expired' => $expired,
                'rule' => $details->hold_rule->value,
                'remaining_business_minutes' => $remainingHold,
            ],
            'sla' => [
                'due_at' => Iso::utc($dueAt),
                'remaining_minutes' => $remainingSla,
                'breached' => $remainingSla < 0,
            ],
        ];
    }

    public static function holdClaim(Booking $booking): ?RoomNightClaim
    {
        $booking->loadMissing('activeClaims');

        return $booking->activeClaims
            ->filter(fn (RoomNightClaim $claim): bool => $claim->kind === ClaimKind::Hold)
            ->sortByDesc('id')
            ->first();
    }
}
