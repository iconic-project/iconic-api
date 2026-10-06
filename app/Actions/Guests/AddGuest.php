<?php

declare(strict_types=1);

namespace App\Actions\Guests;

use App\Actions\Action;
use App\Enums\BookingType;
use App\Events\BookingChargesChanged;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Guests\GuestCapacity;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class AddGuest extends Action
{
    public function __construct(
        private CurrentConfig $config,
        private ApplyGuestFields $fields,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Guest
    {
        return $this->transaction(function () use ($booking, $data, $actor): Guest {
            $booking = BookingMutationLock::acquire($booking);
            $booking->load(['guests']);

            $this->assertWithinLimit($booking);

            $guest = new Guest;
            $guest->booking_id = $booking->id;
            $guest->position = ((int) $booking->guests->max('position')) + 1;
            $guest->is_lead = $booking->guests->isEmpty();
            $guest->ecuador_resident = false;
            $guest->insurance_declared = false;

            $feesBefore = $booking->feesCollectedFresh();

            $this->fields->apply($guest, $data, $actor);
            $guest->save();

            $count = $booking->guests()->count();

            History::record($booking, 'guest.added', after: [
                'guest_id' => $guest->id,
                'position' => $guest->position,
                'what' => 'Guest slot added ('.$count.' guests)',
            ], actor: $actor);

            if ($booking->feesCollectedFresh() !== $feesBefore) {
                BookingChargesChanged::dispatch($booking, 'Fee changed');
            }

            return $guest->fresh() ?? $guest;
        });
    }

    private function assertWithinLimit(Booking $booking): void
    {
        $booking->loadMissing('roomType');
        $max = GuestCapacity::max($booking->type, $this->config->engineSettings()->guests, $booking->roomType);

        if ($booking->guests->count() >= $max) {
            $message = $booking->type === BookingType::Charter
                ? 'Charter capacity is '.$max.' PAX.'
                : 'A suite takes up to '.$max.' guests.';

            throw ValidationException::withMessages([
                'guests' => [$message],
            ]);
        }
    }
}
