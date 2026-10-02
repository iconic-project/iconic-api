<?php

declare(strict_types=1);

namespace App\Actions\Extras;

use App\Actions\Action;
use App\Events\BookingChargesChanged;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\BookingCharges;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Guests\ApplyPng;
use App\Support\History\History;

final class UpdateBookingFees extends Action
{
    public function __construct(
        private CurrentConfig $config,
        private ApplyPng $png,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = BookingMutationLock::acquire($booking, (int) $booking->departure_id);
            BookingCharges::assertWritable($booking);

            $beforePng = (bool) $booking->png_collected;
            $beforeTct = (bool) $booking->tct_collected;

            if (array_key_exists('png_collected', $data)) {
                $booking->png_collected = (bool) $data['png_collected'];
            }

            if (array_key_exists('tct_collected', $data)) {
                $booking->tct_collected = (bool) $data['tct_collected'];
            }

            if ($booking->png_collected && ! $beforePng) {
                $this->png->toBooking($booking);
            }

            if ($booking->tct_collected && ! $beforeTct) {
                $booking->tct_rate_usd = $this->config->engineSettings()->fees->tctPp;
            }

            if ((bool) $booking->png_collected === $beforePng
                && (bool) $booking->tct_collected === $beforeTct
            ) {
                return $booking;
            }

            $booking->save();

            $after = ['what' => $this->what($beforePng, $beforeTct, $booking)];

            History::record($booking, 'booking.fees_changed', before: [
                'png_collected' => $beforePng,
                'tct_collected' => $beforeTct,
            ], after: [
                'png_collected' => (bool) $booking->png_collected,
                'tct_collected' => (bool) $booking->tct_collected,
                'tct_rate_usd' => $booking->tct_rate_usd,
                ...$after,
            ], actor: $actor);

            BookingChargesChanged::dispatch($booking, $after['what']);

            return $booking->fresh() ?? $booking;
        });
    }

    private function what(bool $beforePng, bool $beforeTct, Booking $booking): string
    {
        $lines = [];

        if ((bool) $booking->png_collected !== $beforePng) {
            $lines[] = 'PNG park entry fee'.$this->suffix((bool) $booking->png_collected);
        }

        if ((bool) $booking->tct_collected !== $beforeTct) {
            $lines[] = 'TCT transit card'.$this->suffix((bool) $booking->tct_collected);
        }

        return implode(' · ', $lines);
    }

    private function suffix(bool $collected): string
    {
        return $collected
            ? ' — collected by Iconic (invoiced, due with the balance)'
            : ' — paid directly by the guest (information only on the invoice)';
    }
}
