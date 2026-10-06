<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Action;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Group;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BindDeal extends Action
{
    public function handle(Deal $deal, ?int $bookingId, ?int $groupId): Deal
    {
        if ($deal->isBound()) {
            throw new HttpException(422, 'This deal is already bound.');
        }

        if (($bookingId === null) === ($groupId === null)) {
            throw ValidationException::withMessages([
                'booking_id' => ['Bind a booking or a group.'],
            ]);
        }

        return $this->transaction(function () use ($deal, $bookingId, $groupId): Deal {
            $contact = Contact::resolveIdentity($deal->contact_id) ?? $deal->contact;
            $reference = 'the booking';

            if ($bookingId !== null) {
                $booking = Booking::query()->find($bookingId);

                if (! $booking instanceof Booking || ! $this->sameContact($booking->contact_id, $contact)) {
                    throw ValidationException::withMessages([
                        'booking_id' => ['That booking belongs to another contact.'],
                    ]);
                }

                if ($booking->group_id !== null) {
                    throw ValidationException::withMessages([
                        'booking_id' => ['Bind the group, not one room of it.'],
                    ]);
                }

                $deal->forceFill([
                    'booking_id' => $booking->id,
                    'stage' => null,
                    'type' => OpenDealForBooking::typeFor($booking),
                ])->save();
                $reference = OpenDealForBooking::reference($booking);
            } else {
                $group = Group::query()->find($groupId);

                if (! $group instanceof Group) {
                    throw ValidationException::withMessages([
                        'group_id' => ['That group was not found.'],
                    ]);
                }

                $booking = $group->bookings()->orderBy('id')->first();

                if (! $booking instanceof Booking || ! $this->sameContact($booking->contact_id, $contact)) {
                    throw ValidationException::withMessages([
                        'group_id' => ['That group belongs to another contact.'],
                    ]);
                }

                $deal->forceFill([
                    'group_id' => $group->id,
                    'stage' => null,
                    'type' => OpenDealForBooking::typeFor($booking),
                    'owner_id' => $deal->owner_id ?? $booking->owner_id,
                ])->save();
                $reference = $group->reference;
            }

            History::record($deal, 'deal.bound', after: [
                'booking_id' => $deal->booking_id,
                'group_id' => $deal->group_id,
                'reference' => $reference,
            ]);

            return $deal->refresh();
        });
    }

    private function sameContact(int $bookingContactId, Contact $dealContact): bool
    {
        $resolved = Contact::resolveIdentity($bookingContactId);

        return $resolved instanceof Contact && $resolved->id === $dealContact->id;
    }
}
