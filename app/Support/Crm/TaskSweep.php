<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Actions\Crm\CloseTask;
use App\Actions\Crm\RaiseTask;
use App\Enums\BookingStatus;
use App\Enums\CharterEnquiryStatus;
use App\Enums\DealStage;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RefundRequestStatus;
use App\Enums\SubjectRequestStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Booking;
use App\Models\CharterEnquiry;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\SubjectRequest;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Payments\WireWindow;
use Carbon\CarbonInterface;

final class TaskSweep
{
    public function __construct(
        private readonly RaiseTask $raise,
        private readonly CloseTask $close,
        private readonly CurrentConfig $config,
    ) {}

    public function run(): void
    {
        $this->raiseMissing();
        $this->autoCloseCleared();
    }

    public function onBookingCreated(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Requested) {
            $this->raiseRequest($booking);
        }

        if ($booking->status === BookingStatus::OnHoldAgency) {
            $this->raiseCap($booking);
        }
    }

    public function onBookingStatusChanged(Booking $booking, BookingStatus $from, BookingStatus $to): void
    {
        if ($to === BookingStatus::Requested) {
            $this->raiseRequest($booking);
        }

        if ($from === BookingStatus::Requested && $to !== BookingStatus::Requested) {
            $this->closeKind(TaskKind::RequestResponse, 'request:'.$booking->id, 'the booking left REQUESTED');
        }

        if ($to === BookingStatus::OnHoldAgency) {
            $this->raiseCap($booking);
        }

        if ($from === BookingStatus::OnHoldAgency && $to !== BookingStatus::OnHoldAgency) {
            $this->closeKind(TaskKind::CommissionCap, 'cap:'.$booking->id, 'the booking left ON_HOLD_AGENCY');
        }

        if ($to === BookingStatus::CheckedOut) {
            $this->raisePostTripCall($booking);
        }
    }

    public function onCharterEnquiry(CharterEnquiry $enquiry): void
    {
        if ($enquiry->status === CharterEnquiryStatus::New) {
            $this->raiseCharter($enquiry);
        }
    }

    public function onRefundRequested(RefundRequest $request): void
    {
        if ($request->status === RefundRequestStatus::Pending) {
            $this->raiseRefund($request);
        }
    }

    public function onPaymentAwaitingWire(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::AwaitingWire) {
            $this->raiseWire($payment);
        }
    }

    public function onDealEnteredQuoted(Deal $deal): void
    {
        if ($deal->stage === DealStage::Quoted && ! $deal->isBound()) {
            $this->raiseDealQuote($deal);
        }
    }

    public function onDealLeftQuoted(Deal $deal, CarbonInterface $enteredAt): void
    {
        $this->closeKind(
            TaskKind::DealQuote,
            'deal-quote:'.$deal->id.':'.$enteredAt->utc()->toIso8601String(),
            'the deal left QUOTED',
        );
    }

    private function raiseMissing(): void
    {
        Booking::query()->where('status', BookingStatus::Requested)->orderBy('id')->each(
            fn (Booking $booking) => $this->raiseRequest($booking),
        );

        CharterEnquiry::query()->where('status', CharterEnquiryStatus::New)->orderBy('id')->each(
            fn (CharterEnquiry $enquiry) => $this->raiseCharter($enquiry),
        );

        Booking::query()->overdue()->orderBy('bookings.id')->each(
            fn (Booking $booking) => $this->raiseOverdue($booking),
        );

        Booking::query()->where('status', BookingStatus::OnHoldAgency)->orderBy('id')->each(
            fn (Booking $booking) => $this->raiseCap($booking),
        );

        Payment::query()->where('status', PaymentStatus::AwaitingWire)->orderBy('id')->each(
            fn (Payment $payment) => $this->raiseWire($payment),
        );

        RefundRequest::query()->where('status', RefundRequestStatus::Pending)->orderBy('id')->each(
            fn (RefundRequest $request) => $this->raiseRefund($request),
        );

        Deal::query()
            ->where('stage', DealStage::Quoted->value)
            ->whereNull('booking_id')
            ->whereNull('group_id')
            ->orderBy('id')
            ->each(fn (Deal $deal) => $this->raiseDealQuote($deal));

        SubjectRequest::query()->where('status', SubjectRequestStatus::Open)->orderBy('id')->each(
            fn (SubjectRequest $request) => $this->raiseSubject($request),
        );
    }

    private function autoCloseCleared(): void
    {
        CrmTask::query()
            ->where('status', TaskStatus::Open)
            ->where('source', 'SYSTEM')
            ->orderBy('id')
            ->each(function (CrmTask $task): void {
                $fact = $this->clearedFact($task);

                if ($fact !== null) {
                    $this->close->autoClose($task, $fact);
                }
            });
    }

    private function clearedFact(CrmTask $task): ?string
    {
        return match ($task->kind) {
            TaskKind::RequestResponse => $this->bookingLeft($task, BookingStatus::Requested, 'the booking left REQUESTED'),
            TaskKind::CharterQuote => $this->charterLeft($task),
            TaskKind::OverdueDecision => $this->overdueCleared($task),
            TaskKind::CommissionCap => $this->bookingLeft($task, BookingStatus::OnHoldAgency, 'the booking left ON_HOLD_AGENCY'),
            TaskKind::WireWindow => $this->wireCleared($task),
            TaskKind::RefundDecision => $this->refundCleared($task),
            TaskKind::DealQuote => $this->quoteCleared($task),
            TaskKind::SubjectRequest => $this->subjectCleared($task),
            default => null,
        };
    }

    private function raisePostTripCall(Booking $booking): void
    {
        // TODO(OPEN: 19-05) the post-trip call still measures from the departure return date.
        if ($booking->departure_id === null) {
            return;
        }

        $booking->loadMissing('departure.itinerary');
        $rules = $this->config->businessRules();
        $reference = self::reference($booking);
        $from = BusinessTime::calendarDay($booking->departure->returnDate()->toDateString());

        $this->raise->handle(
            TaskKind::PostTripCall,
            'post-trip-call:'.$booking->id,
            'Post-trip call · '.$reference,
            $reference.' · MKT-006',
            TaskDue::businessDays($from, 2, $rules),
            $booking->owner_id,
            Permission::GuestExperienceManage,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
        );
    }

    private function raiseRequest(Booking $booking): void
    {
        $rules = $this->config->businessRules();
        $reference = self::reference($booking);

        $this->raise->handle(
            TaskKind::RequestResponse,
            'request:'.$booking->id,
            'Respond to '.$reference,
            $reference.' · OPS-009',
            TaskDue::responseHours($booking->created_at, $rules),
            $booking->owner_id,
            null,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
        );
    }

    private function raiseCharter(CharterEnquiry $enquiry): void
    {
        $rules = $this->config->businessRules();
        $deal = Deal::query()->where('charter_enquiry_id', $enquiry->id)->first();

        $this->raise->handle(
            TaskKind::CharterQuote,
            'charter:'.$enquiry->id,
            'Quote the charter enquiry',
            'Charter enquiry · OPS-009',
            TaskDue::responseHours($enquiry->created_at, $rules),
            $deal?->owner_id,
            null,
            contactId: $enquiry->contact_id,
            dealId: $deal?->id,
            charterEnquiryId: $enquiry->id,
        );
    }

    private function raiseOverdue(Booking $booking): void
    {
        $dueDate = $booking->balanceDueDate()->toDateString();
        $reference = self::reference($booking);

        $this->raise->handle(
            TaskKind::OverdueDecision,
            'overdue:'.$booking->id.':'.$dueDate,
            'Overdue decision '.$reference,
            $reference.' · '.Money::format($booking->cruiseOutstanding()).' outstanding · OPS-007',
            TaskDue::endOfGalapagosDay(now()),
            $booking->owner_id,
            Permission::BookingsOverdueDecision,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
        );
    }

    private function raiseCap(Booking $booking): void
    {
        $rules = $this->config->businessRules();
        $reference = self::reference($booking);

        $this->raise->handle(
            TaskKind::CommissionCap,
            'cap:'.$booking->id,
            'Commission cap '.$reference,
            $reference.' · FIN-005',
            TaskDue::businessDays($booking->updated_at, 1, $rules),
            $booking->owner_id,
            Permission::CommissionsOverrideCap,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
        );
    }

    private function raiseWire(Payment $payment): void
    {
        $payment->loadMissing('booking');
        $booking = $payment->booking;
        $reference = self::reference($booking);

        $this->raise->handle(
            TaskKind::WireWindow,
            'wire:'.$payment->id,
            'Wire window '.$reference,
            $reference.' · '.Money::format($payment->amount).' · wire window',
            WireWindow::endsAt($payment->created_at),
            $booking->owner_id,
            Permission::PaymentsMarkWireReceived,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
            paymentId: $payment->id,
        );
    }

    private function raiseRefund(RefundRequest $request): void
    {
        $request->loadMissing('booking');
        $booking = $request->booking;
        $reference = self::reference($booking);
        $rules = $this->config->businessRules();

        $this->raise->handle(
            TaskKind::RefundDecision,
            'refund:'.$request->id,
            'Refund decision '.$reference,
            $reference.' · '.Money::format($request->refund_due).' due · refund SLA',
            TaskDue::businessDays($request->created_at, $rules->sla->refundBusinessDays, $rules),
            $booking->owner_id,
            Permission::RefundsApprove,
            contactId: $booking->contact_id,
            bookingId: $booking->id,
            refundRequestId: $request->id,
        );
    }

    private function raiseDealQuote(Deal $deal): void
    {
        $rules = $this->config->businessRules();
        $entered = $deal->stage_entered_at->utc()->toIso8601String();

        $this->raise->handle(
            TaskKind::DealQuote,
            'deal-quote:'.$deal->id.':'.$entered,
            'Quote follow-up '.$deal->title,
            $deal->title.' · OPS-009',
            TaskDue::responseHours($deal->stage_entered_at, $rules),
            $deal->owner_id,
            null,
            contactId: $deal->contact_id,
            dealId: $deal->id,
        );
    }

    private function closeKind(TaskKind $kind, string $key, string $fact): void
    {
        $task = CrmTask::query()->where('idempotency_key', $key)->where('kind', $kind)->first();

        if ($task instanceof CrmTask) {
            $this->close->autoClose($task, $fact);
        }
    }

    private function bookingLeft(CrmTask $task, BookingStatus $status, string $fact): ?string
    {
        $booking = $task->booking_id !== null ? Booking::query()->find($task->booking_id) : null;

        if (! $booking instanceof Booking || $booking->status !== $status) {
            return $fact;
        }

        return null;
    }

    private function charterLeft(CrmTask $task): ?string
    {
        $enquiry = $task->charter_enquiry_id !== null
            ? CharterEnquiry::query()->find($task->charter_enquiry_id)
            : null;

        if (! $enquiry instanceof CharterEnquiry || $enquiry->status !== CharterEnquiryStatus::New) {
            return 'the enquiry left NEW';
        }

        return null;
    }

    private function overdueCleared(CrmTask $task): ?string
    {
        $booking = $task->booking_id !== null ? Booking::query()->find($task->booking_id) : null;

        if (! $booking instanceof Booking || ! $booking->isOverdue()) {
            return 'the overdue flag cleared';
        }

        return null;
    }

    private function wireCleared(CrmTask $task): ?string
    {
        $payment = $task->payment_id !== null ? Payment::query()->find($task->payment_id) : null;

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::AwaitingWire) {
            return 'the wire was received or released';
        }

        return null;
    }

    private function refundCleared(CrmTask $task): ?string
    {
        $request = $task->refund_request_id !== null ? RefundRequest::query()->find($task->refund_request_id) : null;

        if (! $request instanceof RefundRequest || $request->status !== RefundRequestStatus::Pending) {
            return 'the refund was approved, declined or executed';
        }

        return null;
    }

    private function quoteCleared(CrmTask $task): ?string
    {
        $deal = $task->deal_id !== null ? Deal::query()->find($task->deal_id) : null;

        if (! $deal instanceof Deal || $deal->isBound() || $deal->stage !== DealStage::Quoted) {
            return 'the deal left QUOTED';
        }

        return null;
    }

    private function raiseSubject(SubjectRequest $request): void
    {
        $this->raise->handle(
            TaskKind::SubjectRequest,
            'subject:'.$request->id,
            $request->type->label().' request',
            $request->type->value.' · privacy SLA',
            $request->due_at,
            $request->created_by,
            Permission::PrivacyManage,
            contactId: $request->contact_id,
            subjectRequestId: $request->id,
        );
    }

    private function subjectCleared(CrmTask $task): ?string
    {
        $request = $task->subject_request_id !== null
            ? SubjectRequest::query()->find($task->subject_request_id)
            : null;

        if (! $request instanceof SubjectRequest || $request->status !== SubjectRequestStatus::Open) {
            return 'the subject request closed';
        }

        return null;
    }

    public static function reference(Booking $booking): string
    {
        if (is_string($booking->reference) && $booking->reference !== '') {
            return $booking->reference;
        }

        if (is_string($booking->request_reference) && $booking->request_reference !== '') {
            return $booking->request_reference;
        }

        return 'booking '.$booking->id;
    }
}
