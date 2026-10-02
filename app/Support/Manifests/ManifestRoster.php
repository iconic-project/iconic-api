<?php

declare(strict_types=1);

namespace App\Support\Manifests;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Models\Departure;
use App\Models\Guest;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

final class ManifestRoster
{
    /** @var list<BookingStatus> */
    public const COUNTED = [
        BookingStatus::Confirmed,
        BookingStatus::OnHoldAgency,
        BookingStatus::FullyPaid,
        BookingStatus::OnBoard,
        BookingStatus::Completed,
    ];

    /**
     * Guests on sold bookings for this departure, cabin then position.
     *
     * @return Collection<int, ManifestPassenger>
     */
    public static function passengers(Departure $departure): Collection
    {
        $guests = Guest::query()
            ->select('guests.*')
            ->join('bookings', function (JoinClause $join): void {
                $join->on('bookings.id', '=', 'guests.booking_id')
                    ->whereNull('bookings.deleted_at');
            })
            ->leftJoin('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->where('bookings.departure_id', $departure->id)
            ->whereIn('bookings.status', array_map(
                fn (BookingStatus $status): string => $status->value,
                self::COUNTED,
            ))
            ->orderByRaw('rooms.sort IS NULL')
            ->orderBy('rooms.sort')
            ->orderBy('guests.position')
            ->orderBy('guests.id')
            ->with(['booking.cabin', 'currentPreference'])
            ->get();

        return $guests->values()->map(
            fn (Guest $guest, int $index): ManifestPassenger => new ManifestPassenger($guest, $index + 1, $departure),
        );
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $passengers
     */
    public static function isCharter(Collection $passengers): bool
    {
        return $passengers->contains(
            fn (ManifestPassenger $passenger): bool => $passenger->guest->booking->type === BookingType::Charter,
        );
    }
}
