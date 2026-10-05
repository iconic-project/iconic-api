<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\BusinessTime;

final class Transitions
{
    /**
     * @return list<BookingStatus>
     */
    public static function targets(BookingStatus $from): array
    {
        return match ($from) {
            BookingStatus::Requested => [
                BookingStatus::PendingPayment,
                BookingStatus::Confirmed,
                BookingStatus::Released,
                BookingStatus::Cancelled,
            ],
            BookingStatus::PendingPayment => [
                BookingStatus::Confirmed,
                BookingStatus::Cancelled,
            ],
            BookingStatus::Confirmed => [
                BookingStatus::FullyPaid,
                BookingStatus::Cancelled,
            ],
            BookingStatus::FullyPaid => [
                BookingStatus::Confirmed,
                BookingStatus::CancelledPostpaid,
            ],
            BookingStatus::InHouse => [],
            BookingStatus::OnHoldAgency => [
                BookingStatus::Confirmed,
                BookingStatus::Released,
                BookingStatus::Cancelled,
            ],
            default => [],
        };
    }

    public static function reasonRequired(BookingStatus $to): bool
    {
        return in_array($to, [
            BookingStatus::Cancelled,
            BookingStatus::CancelledPostpaid,
            BookingStatus::FullyPaid,
            BookingStatus::Released,
        ], true);
    }

    public static function dateGuardAllows(
        BookingStatus $to,
        string $today,
        string $departureDate,
        string $returnDate,
    ): bool {
        return true;
    }

    public static function dateGuardAllowsFor(Booking $booking, BookingStatus $to): bool
    {
        $stay = $booking->stay();

        return self::dateGuardAllows(
            $to,
            BusinessTime::now()->toDateString(),
            $stay->checkIn()->toDateString(),
            $stay->checkOut()->toDateString(),
        );
    }

    /**
     * @return list<BookingStatus>
     */
    public static function legalTargets(Booking $booking): array
    {
        return array_values(array_filter(
            self::targets($booking->status),
            function (BookingStatus $to) use ($booking): bool {
                if ($booking->status === BookingStatus::OnHoldAgency
                    && $to === BookingStatus::Confirmed
                    && ! $booking->commission_approved
                ) {
                    return false;
                }

                return self::dateGuardAllowsFor($booking, $to);
            },
        ));
    }

    /**
     * @return list<array{to: string, reason_required: bool}>
     */
    public static function allowedFor(Booking $booking, User $actor): array
    {
        if (! $actor->hasPermission(Permission::BookingsChangeStatus)) {
            return [];
        }

        if (! app(BookingPolicy::class)->ownsOrMayActOnAny($actor, $booking)) {
            return [];
        }

        return array_map(
            fn (BookingStatus $to): array => [
                'to' => $to->value,
                'reason_required' => self::reasonRequired($to),
            ],
            self::legalTargets($booking),
        );
    }

    /**
     * @param  list<BookingStatus>  $allowed
     */
    public static function illegalMessage(BookingStatus $from, BookingStatus $to, array $allowed): string
    {
        $list = $allowed === []
            ? 'none'
            : implode(', ', array_map(fn (BookingStatus $status): string => $status->value, $allowed));

        $message = 'Cannot change status from '.$from->value.' to '.$to->value.'. Allowed: '.$list.'.';
        $pointer = self::frontDeskPointer($to);

        return $pointer === null ? $message : $message.' '.$pointer;
    }

    public static function frontDeskPointer(BookingStatus $to): ?string
    {
        return match ($to) {
            BookingStatus::InHouse => 'Check in at POST /api/rms/bookings/{booking}/check-in.',
            BookingStatus::CheckedOut => 'Check out at POST /api/rms/bookings/{booking}/check-out.',
            BookingStatus::NoShow => 'Mark a no-show at POST /api/rms/bookings/{booking}/no-show.',
            default => null,
        };
    }

    public static function statusLabel(BookingStatus $status): string
    {
        return str_replace('_', ' ', $status->value);
    }
}
