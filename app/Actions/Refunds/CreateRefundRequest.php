<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Action;
use App\Enums\BookingType;
use App\Enums\RefundRequestStatus;
use App\Events\RefundRequested;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessHours;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Payments\CancellationBands;
use App\Support\Payments\CancellationPenalty;
use App\Support\Payments\Ledger;

final class CreateRefundRequest extends Action
{
    public function __construct(private readonly CurrentConfig $config) {}

    public function handle(Booking $booking, ?User $actor, bool $system = false): ?RefundRequest
    {
        return $this->transaction(function () use ($booking, $actor, $system): ?RefundRequest {
            if ($booking->refundRequest()->where('status', RefundRequestStatus::Pending)->exists()) {
                return $booking->refundRequest()->where('status', RefundRequestStatus::Pending)->first();
            }

            $paid = Ledger::paid($booking);

            if ($paid <= 0) {
                History::record($booking, 'refund.not_due', after: [
                    'paid' => 0,
                    'what' => 'Nothing was paid, so nothing is owed.',
                ], actor: $system ? null : $actor, system: $system);

                return null;
            }

            $rules = $this->config->businessRules();
            $cancelledAt = BusinessTime::now();
            $days = BusinessTime::calendarDaysBetween(
                $cancelledAt->toDateString(),
                $booking->stay()->checkIn()->toDateString(),
            );
            $charter = $booking->type === BookingType::Charter;
            $bandList = app(CancellationBands::class)->forBooking($booking);
            $band = CancellationPenalty::bandFor($days, $bandList);
            $penalty = CancellationPenalty::penalty($booking->total, $band['penalty_pct']);
            $refundDue = CancellationPenalty::refundDue($paid, $penalty);
            $hours = BusinessHours::fromDocument($rules);
            $dueBy = $hours->endOfNthBusinessDay($cancelledAt, $rules->sla->refundBusinessDays);

            $request = RefundRequest::query()->create([
                'booking_id' => $booking->id,
                'cancelled_at' => $cancelledAt,
                'days_before_arrival' => $days,
                'band_min_days' => $band['min_days'],
                'band_source' => $charter ? 'CHARTER' : 'CAB'.'IN',
                'penalty_pct' => $band['penalty_pct'],
                'penalty_amount' => $penalty,
                'paid_at_cancellation' => $paid,
                'refund_due' => $refundDue,
                'status' => RefundRequestStatus::Pending,
                'due_by' => $dueBy,
            ]);

            RefundRequested::dispatch($request);

            History::record($booking, 'refund.requested', after: [
                'band_min_days' => $band['min_days'],
                'band_source' => $charter ? 'CHARTER' : 'CAB'.'IN',
                'penalty_pct' => $band['penalty_pct'],
                'penalty_amount' => $penalty,
                'refund_due' => $refundDue,
                'days_before_arrival' => $days,
            ], actor: $system ? null : $actor, system: $system);

            return $request;
        });
    }
}
