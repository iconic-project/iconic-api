<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Support\Documents\IssueOnce;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SendOnBookingStatusChanged implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly IssueOnce $issueOnce) {}

    public function handle(BookingStatusChanged $event): void
    {
        $booking = Booking::query()->find($event->booking->id);

        if (! $booking instanceof Booking) {
            return;
        }

        // Cruise invoice snapshots need a departure. A stay has none.
        // TODO(OPEN: 19-03) hotel documents are not issued on these transitions.
        if ($booking->departure_id === null) {
            return;
        }

        if (in_array($booking->status, [
            BookingStatus::Cancelled,
            BookingStatus::CancelledPostpaid,
            BookingStatus::Released,
            BookingStatus::Requested,
        ], true)) {
            return;
        }

        if ($event->to === BookingStatus::Confirmed || $booking->status->isConfirmedOrLater()) {
            if (! in_array($booking->status, [
                BookingStatus::PendingPayment,
                BookingStatus::OnHoldAgency,
            ], true)) {
                $this->issueOnce->handle($booking, DocumentKind::Invoice);
                $this->issueOnce->handle($booking, DocumentKind::Summary);
            }
        }

        if ($event->to === BookingStatus::FullyPaid) {
            $this->issueOnce->handle($booking, DocumentKind::FinalInvoice);
        }
    }
}
