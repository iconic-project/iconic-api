<?php

declare(strict_types=1);

namespace App\Actions\Guests;

use App\Actions\Action;
use App\Models\Guest;
use App\Models\User;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Guests\GuestFieldLabels;
use App\Support\History\History;

final class UpdateGuest extends Action
{
    public function __construct(
        private ApplyGuestFields $fields,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Guest $guest, array $data, ?User $actor = null, ?string $actorLabel = null): Guest
    {
        return $this->transaction(function () use ($guest, $data, $actor, $actorLabel): Guest {
            $guest->load('booking');
            $booking = $guest->booking;
            $booking = BookingMutationLock::acquire($booking);
            $guest->setRelation('booking', $booking);

            $this->fields->apply($guest, $data, $actor);

            if (! $guest->isDirty()) {
                return $guest;
            }

            $guest->save();

            $consentChanged = $guest->wasChanged('guardian_consented_at')
                || $guest->wasChanged('guardian_recorded_by');
            $labels = GuestFieldLabels::changed($guest);

            if ($labels !== []) {
                [$before, $after] = History::diff($guest);
                $after['what'] = 'Passenger updated — '.$guest->displayName().': '.implode(', ', $labels);
                $after['guest_id'] = $guest->id;

                History::record($booking, 'guest.updated', before: $before, after: $after, actor: $actor, actorLabel: $actorLabel);
            }

            if ($consentChanged) {
                History::record($booking, 'guest.guardian_consented', before: [
                    'guardian_consented_at' => $guest->getPrevious()['guardian_consented_at'] ?? null,
                    'guardian_recorded_by' => $guest->getPrevious()['guardian_recorded_by'] ?? null,
                ], after: [
                    'guest_id' => $guest->id,
                    'guardian_consented_at' => $guest->guardian_consented_at,
                    'guardian_recorded_by' => $guest->guardian_recorded_by,
                    'what' => $guest->guardian_consented_at === null
                        ? 'Guardian consent cleared — '.$guest->displayName()
                        : 'Guardian consent recorded — '.$guest->displayName(),
                ], actor: $actor, actorLabel: $actorLabel);
            }

            return $guest->fresh() ?? $guest;
        });
    }
}
