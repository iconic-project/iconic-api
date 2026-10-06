<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Actions\Complete\RevokeCompleteAccessTokens;
use App\Actions\Refunds\CreateRefundRequest;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\ReferenceType;
use App\Enums\ReleaseReason;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Services\References\ReferenceService;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Bookings\ConfirmRequestRooms;
use App\Support\Bookings\Transitions;
use App\Support\History\History;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

final class TransitionBooking extends Action
{
    public function __construct(
        private ClaimService $roomClaims,
        private ConfirmRequestRooms $confirmRooms,
        private ReferenceService $references,
        private CreateRefundRequest $refunds,
        private RevokeCompleteAccessTokens $completeTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, ?User $actor, bool $system = false, ?string $actorLabel = null): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor, $system, $actorLabel): Booking {
            $booking = BookingMutationLock::acquire($booking);
            $booking->load(['property', 'room', 'roomType', 'contact', 'claims']);

            $to = $data['to'] instanceof BookingStatus
                ? $data['to']
                : BookingStatus::from((string) $data['to']);

            $allowed = Transitions::legalTargets($booking);

            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages([
                    'to' => [Transitions::illegalMessage($booking->status, $to, $allowed)],
                ]);
            }

            $reason = $this->reason($data);
            $from = $booking->status;

            $this->applyClaims($booking, $from, $to, $actor, $system, $data);

            if ($to === BookingStatus::Confirmed && $booking->reference === null) {
                $booking->reference = $this->references->next(ReferenceType::Booking);
            }

            $what = $this->wording($booking, $from, $to, $data);
            $client = $booking->contact->name;

            $booking->status = $to;
            $booking->save();

            $event = $to === BookingStatus::Released ? 'booking.released' : 'booking.status_changed';

            History::record($booking, $event, before: [
                'status' => $from->value,
            ], after: [
                'status' => $to->value,
                'what' => $what,
                'client' => $client,
            ], reason: $reason, actor: $system ? null : $actor, system: $system, actorLabel: $system ? $actorLabel : null);

            if ($from !== $to) {
                BookingStatusChanged::dispatch($booking, $from, $to);
            }

            return $booking->refresh()->load([
                'property',
                'room',
                'roomType',
                'contact',
                'group.coordinator',
                'owner',
                'ratesVersion',
                'refundRequest',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): ?string
    {
        if (! isset($data['reason']) || ! is_string($data['reason'])) {
            return null;
        }

        $reason = trim($data['reason']);

        return $reason === '' ? null : $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wording(Booking $booking, BookingStatus $from, BookingStatus $to, array $data = []): string
    {
        if (isset($data['what']) && is_string($data['what']) && $data['what'] !== '') {
            return $data['what'];
        }

        if ($to === BookingStatus::Released) {
            return $from === BookingStatus::Requested
                ? 'Request released — hold returned to inventory'
                : 'Reservation released — room returned to inventory';
        }

        $what = 'Status '.Transitions::statusLabel($from).' → '.Transitions::statusLabel($to);

        if ($from === BookingStatus::Requested && $to === BookingStatus::PendingPayment) {
            $booking->loadMissing('bookingRequest');
            $request = $booking->bookingRequest;
            $channel = $request instanceof BookingRequest
                ? $request->preferred_channel->value
                : 'EMAIL';
            $what .= ' · deposit link to be sent via '.$channel;
            // TODO(Sprint 5): send the deposit link
        }

        if ($to === BookingStatus::FullyPaid && $booking->balance() > 0) {
            $what .= ' (marked manually — '.Money::format($booking->balance()).' not in the payments record)';
        }

        return $what;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyClaims(
        Booking $booking,
        BookingStatus $from,
        BookingStatus $to,
        ?User $actor,
        bool $system,
        array $data,
    ): void {
        if ($from === BookingStatus::Requested
            && in_array($to, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)
        ) {
            $this->confirmStay($booking, $actor, $data);

            return;
        }

        if (in_array($to, [
            BookingStatus::Released,
            BookingStatus::Cancelled,
            BookingStatus::CancelledPostpaid,
        ], true)) {
            $reason = $to === BookingStatus::Released ? ReleaseReason::Released : ReleaseReason::Cancelled;

            $this->roomClaims->release($booking, $reason);
            $this->completeTokens->handle($booking);

            if (in_array($to, [BookingStatus::Cancelled, BookingStatus::CancelledPostpaid], true)) {
                $this->refunds->handle($booking, $actor, $system);
            }

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmStay(Booking $booking, ?User $actor, array $data): void
    {
        $live = $this->confirmRooms->preview($booking, $actor, $data);

        if ($live['convert']) {
            $converted = $this->roomClaims->convert($booking, $booking, ClaimKind::Booking);

            if ($converted >= 1) {
                return;
            }
        }

        $offer = $this->confirmRooms->accepted($booking, $actor, $data);
        $room = $offer['room'];
        $this->roomClaims->claim($booking->stay(), collect([$room]), $booking, ClaimKind::Booking);

        if ((int) $booking->room_id !== $room->id) {
            $booking->room_id = $room->id;
            $booking->setRelation('room', $room);
            $booking->save();
        }
    }
}
