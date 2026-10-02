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
use App\Exceptions\CabinUnavailableException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Cabin;
use App\Models\CabinClaim;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Services\References\ReferenceService;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Bookings\Transitions;
use App\Support\History\History;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TransitionBooking extends Action
{
    public function __construct(
        private ClaimService $claims,
        private ReferenceService $references,
        private CreateRefundRequest $refunds,
        private RevokeCompleteAccessTokens $completeTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(Booking $booking, array $data, ?User $actor, bool $system = false, ?string $actorLabel = null): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor, $system, $actorLabel): Booking {
            $expectedDepartureId = (int) $booking->departure_id;
            $booking = BookingMutationLock::acquire($booking, $expectedDepartureId);
            $booking->load(['departure.property.cabins', 'cabin', 'contact', 'claims']);

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

            $this->applyClaims($booking, $from, $to, $actor, $system);

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
                'departure.property',
                'cabin',
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
                : 'Reservation released — cabin returned to inventory';
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

    private function applyClaims(
        Booking $booking,
        BookingStatus $from,
        BookingStatus $to,
        ?User $actor,
        bool $system,
    ): void {
        if ($from === BookingStatus::Requested
            && in_array($to, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)
        ) {
            $this->confirmRequest($booking);

            return;
        }

        if (in_array($to, [
            BookingStatus::Released,
            BookingStatus::Cancelled,
            BookingStatus::CancelledPostpaid,
        ], true)) {
            $this->claims->release(
                $booking,
                $to === BookingStatus::Released ? ReleaseReason::Released : ReleaseReason::Cancelled,
            );
            $this->completeTokens->handle($booking);

            if (in_array($to, [BookingStatus::Cancelled, BookingStatus::CancelledPostpaid], true)) {
                $this->refunds->handle($booking, $actor, $system);
            }

            return;
        }
    }

    private function confirmRequest(Booking $booking): void
    {
        $needed = $this->cabinsFor($booking)->count();
        $activeHold = $booking->claims
            ->first(fn (CabinClaim $claim): bool => $claim->released_at === null && $claim->kind === ClaimKind::Hold);

        if ($activeHold instanceof CabinClaim) {
            $converted = $this->claims->convert($booking, $booking, ClaimKind::Booking);

            if ($converted >= $needed) {
                return;
            }
        }

        try {
            $this->claims->claim($booking->departure, $this->cabinsFor($booking), $booking, ClaimKind::Booking);
        } catch (CabinUnavailableException $exception) {
            throw new CabinUnavailableException(
                $exception->unavailable,
                "The cabin was taken after this request's hold expired.",
            );
        }
    }

    /**
     * @return Collection<int, Cabin>
     */
    private function cabinsFor(Booking $booking): Collection
    {
        if ($booking->cabin instanceof Cabin) {
            return collect([$booking->cabin]);
        }

        return $booking->departure->property->cabins->sortBy('sort')->values();
    }
}
