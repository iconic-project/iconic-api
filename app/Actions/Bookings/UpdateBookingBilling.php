<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;

final class UpdateBookingBilling extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, ?User $actor = null, ?string $actorLabel = null): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor, $actorLabel): Booking {
            $booking = BookingMutationLock::acquire($booking);

            $fields = ['billing_name', 'billing_address', 'billing_email', 'billing_phone'];
            $before = [];
            $after = [];

            foreach ($fields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $value = is_string($value) && trim($value) === '' ? null : (is_string($value) ? $value : null);

                if ($booking->{$field} === $value) {
                    continue;
                }

                $before[$field] = $booking->{$field};
                $booking->{$field} = $value;
                $after[$field] = $value;
            }

            if ($after === []) {
                return $booking->refresh()->load([
                    'property',
                    'room.roomType',
                    'contact',
                    'group.coordinator',
                    'owner',
                    'agency',
                    'ratesVersion',
                ]);
            }

            $booking->save();

            History::record($booking, 'booking.billing_changed', before: $before, after: $after, actor: $actor, actorLabel: $actorLabel);

            return $booking->refresh()->load([
                'property',
                'room.roomType',
                'contact',
                'group.coordinator',
                'owner',
                'agency',
                'ratesVersion',
            ]);
        });
    }
}
