<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class UpdateBooking extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = BookingMutationLock::acquire($booking);
            $booking->load('owner');

            if (array_key_exists('internal_notes', $data)) {
                $notes = $data['internal_notes'];
                $notes = is_string($notes) ? $notes : null;
                $before = $booking->internal_notes;

                if ($before !== $notes) {
                    $booking->internal_notes = $notes;
                    $booking->save();
                    History::record($booking, 'booking.updated', before: [
                        'internal_notes' => $before,
                    ], after: [
                        'internal_notes' => $notes,
                    ], actor: $actor);
                }
            }

            if (array_key_exists('owner_id', $data)) {
                $this->reassign($booking, (int) $data['owner_id'], $actor);
            }

            return $booking->refresh()->load([
                'property',
                'room',
                'contact',
                'group.coordinator',
                'owner',
                'ratesVersion',
            ]);
        });
    }

    private function reassign(Booking $booking, int $ownerId, User $actor): void
    {
        $owner = User::query()->with('role')->find($ownerId);

        if (! $owner instanceof User
            || $owner->status !== UserStatus::Active
            || ! $owner->hasPermission(Permission::PanelRms)
        ) {
            throw ValidationException::withMessages([
                'owner_id' => ['The new owner must be an active user with panel.rms.'],
            ]);
        }

        if ((int) $booking->owner_id === $ownerId) {
            return;
        }

        $beforeId = (int) $booking->owner_id;
        $beforeName = $booking->owner->name;

        $booking->owner_id = $ownerId;
        $booking->save();
        $booking->load('owner');

        History::record($booking, 'booking.owner_changed', before: [
            'owner_id' => $beforeId,
            'owner_name' => $beforeName,
        ], after: [
            'owner_id' => $owner->id,
            'owner_name' => $owner->name,
        ], actor: $actor);
    }
}
