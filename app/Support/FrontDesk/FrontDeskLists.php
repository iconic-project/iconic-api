<?php

declare(strict_types=1);

namespace App\Support\FrontDesk;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class FrontDeskLists
{
    /**
     * Sold stays that occupy the night. The in-house tab stays status IN_HOUSE only.
     *
     * @var list<BookingStatus>
     */
    public const OCCUPYING = [
        BookingStatus::Confirmed,
        BookingStatus::FullyPaid,
        BookingStatus::InHouse,
        BookingStatus::CheckedOut,
        BookingStatus::Overdue,
    ];

    /**
     * @return array{arrivals: Collection<int, Booking>, in_house: Collection<int, Booking>, departures: Collection<int, Booking>}
     */
    public function forDate(User $actor, string $date): array
    {
        return [
            'arrivals' => $this->base($actor)->arrivingBetween($date, $date)->get(),
            'in_house' => $this->base($actor)->inHouseOn($date)->get(),
            'departures' => $this->base($actor)->checkingOutBetween($date, $date)->get(),
        ];
    }

    /**
     * Guests of bookings whose night falls inside [check_in, check_out).
     *
     * @return Collection<int, Booking>
     */
    public function occupying(User $actor, string $date): Collection
    {
        return $this->base($actor)
            ->whereDate('bookings.check_in', '<=', $date)
            ->whereDate('bookings.check_out', '>', $date)
            ->whereIn('bookings.status', array_map(
                fn (BookingStatus $status): string => $status->value,
                self::OCCUPYING,
            ))
            ->with('guests')
            ->get();
    }

    /**
     * @return Builder<Booking>
     */
    private function base(User $actor): Builder
    {
        return Booking::query()
            ->select('bookings.*')
            ->visibleTo($actor)
            ->withLedgerAggregates()
            ->withGuestSummary()
            ->withChargesSummary()
            ->with([
                'departure.property',
                'departure.itinerary',
                'cabin.roomType',
                'room.roomType',
                'roomType',
                'property',
                'contact',
                'group.coordinator',
                'owner',
                'agency',
            ])
            ->orderBy('bookings.id');
    }
}
