<?php

declare(strict_types=1);

namespace App\Actions\Extras;

use App\Actions\Action;
use App\Events\BookingChargesChanged;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\BookingCharges;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;

final class UpdateBookingFees extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = BookingMutationLock::acquire($booking);
            BookingCharges::assertWritable($booking);

            $beforeTct = (bool) $booking->tct_collected;

            if (array_key_exists('tct_collected', $data)) {
                $booking->tct_collected = (bool) $data['tct_collected'];
            }

            if ($booking->tct_collected && ! $beforeTct && $booking->tct_rate_usd === null) {
                $booking->tct_rate_usd = 0;
            }

            if ((bool) $booking->tct_collected === $beforeTct) {
                return $booking;
            }

            $booking->save();

            $after = ['what' => $this->what($booking)];

            History::record($booking, 'booking.fees_changed', before: [
                'tct_collected' => $beforeTct,
            ], after: [
                'tct_collected' => (bool) $booking->tct_collected,
                'tct_rate_usd' => $booking->tct_rate_usd,
                ...$after,
            ], actor: $actor);

            BookingChargesChanged::dispatch($booking, $after['what']);

            return $booking->fresh() ?? $booking;
        });
    }

    private function what(Booking $booking): string
    {
        return 'TCT transit card'.$this->suffix((bool) $booking->tct_collected);
    }

    private function suffix(bool $collected): string
    {
        return $collected
            ? ' — collected by Iconic (invoiced, due with the balance)'
            : ' — paid directly by the guest (information only on the invoice)';
    }
}
