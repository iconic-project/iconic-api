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
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class RequestSummary
{
    /**
     * @return array{
     *     preferred_channel: string,
     *     travel_advisor: bool,
     *     notes: string|null,
     *     hold: array{expires_at: string|null, expired: bool, rule: string, remaining_business_minutes: int},
     *     sla: array{due_at: string, remaining_minutes: int, breached: bool},
     *     copy: string
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
            'copy' => self::line(self::roomsCount($booking), $booking->stay()),
        ];
    }

    public static function line(int $rooms, StayDates $stay): string
    {
        $roomLabel = $rooms === 1 ? '1 room' : $rooms.' rooms';
        $nights = $stay->nights();
        $nightLabel = $nights === 1 ? '1 night' : $nights.' nights';

        return $roomLabel.' · '.self::range($stay->checkIn(), $stay->checkOut()).' · '.$nightLabel;
    }

    public static function roomsCount(Booking $booking): int
    {
        $booking->loadMissing('activeClaims');

        $ids = $booking->activeClaims
            ->pluck('room_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isNotEmpty()) {
            return $ids->count();
        }

        return $booking->room_id === null ? 0 : 1;
    }

    private static function range(CarbonImmutable $checkIn, CarbonImmutable $checkOut): string
    {
        if ($checkIn->format('Y-m') === $checkOut->format('Y-m')) {
            return $checkIn->format('D j').' – '.$checkOut->format('D j M Y');
        }

        if ($checkIn->format('Y') === $checkOut->format('Y')) {
            return $checkIn->format('D j M').' – '.$checkOut->format('D j M Y');
        }

        return $checkIn->format('D j M Y').' – '.$checkOut->format('D j M Y');
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
