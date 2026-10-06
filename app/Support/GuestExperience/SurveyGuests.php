<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestResponse;
use Illuminate\Support\Collection;

final class SurveyGuests
{
    /**
     * Names and rooms for the staff post-trip form. No passport or notes.
     *
     * @return Collection<int, array{guest_id: int, name: string, room: string, responded: bool}>
     */
    public static function forBooking(Booking $booking): Collection
    {
        $booking->loadMissing(['guests', 'room']);

        $responded = GuestResponse::query()
            ->where('booking_id', $booking->id)
            ->pluck('guest_id')
            ->all();

        return $booking->guests
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->map(function (Guest $guest) use ($booking, $responded): array {
                $guest->setRelation('booking', $booking);

                return [
                    'guest_id' => $guest->id,
                    'name' => $guest->displayName(),
                    'room' => $guest->booking->roomLabel(),
                    'responded' => in_array($guest->id, $responded, true),
                ];
            });
    }
}
