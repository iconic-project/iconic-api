<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\ReleaseReason;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Support\Bookings\FrontDeskLock;
use App\Support\History\History;
use App\Support\Payments\CancellationPenalty;
use App\Support\Rounding;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Illuminate\Validation\ValidationException;

final class MarkNoShow extends Action
{
    public function __construct(
        private StayClock $clock,
        private CurrentConfig $config,
        private ClaimService $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);
            $stay = $booking->stay();

            if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::FullyPaid], true)) {
                throw ValidationException::withMessages([
                    'status' => ['A no-show is only from confirmed or fully paid.'],
                ]);
            }

            if (! $this->clock->isNoShowWindow($stay)) {
                throw ValidationException::withMessages([
                    'check_in' => ['A no-show is allowed after the arrival day, or on the arrival day after the cut-off.'],
                ]);
            }

            $reason = isset($data['reason']) && is_string($data['reason']) ? trim($data['reason']) : '';

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required to mark a no-show.'],
                ]);
            }

            $this->releaseRemainingNights($booking, $stay);
            $charge = $this->charge($booking);
            $from = $booking->status;
            $beforeTotal = $booking->total;

            $booking->price_lines = [[
                'code' => 'NO_SHOW',
                'label' => 'No-show charge',
                'amount' => $charge,
            ]];
            $booking->total = $charge;
            $booking->status = BookingStatus::NoShow;
            $booking->no_show_at = now();
            $booking->save();

            History::record($booking, 'booking.no_show', before: [
                'status' => $from->value,
                'total' => $beforeTotal,
            ], after: [
                'status' => $booking->status->value,
                'total' => $charge,
                'what' => 'No-show · nights after arrival released',
            ], reason: $reason, actor: $actor);

            BookingStatusChanged::dispatch($booking, $from, $booking->status);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
    }

    private function releaseRemainingNights(Booking $booking, StayDates $stay): void
    {
        $from = $stay->checkIn()->addDay()->toDateString();
        $to = $stay->checkOut()->toDateString();

        if ($from >= $to) {
            return;
        }

        $this->claims->release($booking, ReleaseReason::NoShow, nights: StayDates::of($from, $to));
    }

    private function charge(Booking $booking): int
    {
        $set = 'standard';
        $code = $booking->rate_plan_code;

        foreach ($this->config->rates()->ratePlans as $plan) {
            if ($code !== null && $plan->code === $code) {
                $set = $plan->cancellation;
            }
        }

        $bands = $this->config->businessRules()->cancellationSets[$set]
            ?? $this->config->businessRules()->cancellationSets['standard']
            ?? [];

        if ($bands === []) {
            throw ValidationException::withMessages([
                'rate_plan' => ['This rate plan has no cancellation set.'],
            ]);
        }

        $band = CancellationPenalty::bandFor(0, $bands);
        $pct = (int) $band['penalty_pct'];

        return Rounding::halfUp($booking->total * $pct / 100);
    }
}
