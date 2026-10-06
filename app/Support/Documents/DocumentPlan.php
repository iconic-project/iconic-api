<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentPlanKind;
use App\Enums\DocumentPlanStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Documents\Snapshots\DocumentFacts;
use Illuminate\Support\Collection;

final class DocumentPlan
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly CurrentConfig $config,
    ) {}

    /**
     * @return list<DocumentPlanRow>
     */
    public function for(Booking $booking, ?User $actor = null): array
    {
        $booking->loadMissing([
            'departure',
            'contact',
            'group.coordinator',
            'agency',
            'guests',
            'extras',
            'payments',
            'documents.deliveries',
            'deliveries',
        ]);

        $canAct = $actor instanceof User && $actor->can('issueDocument', $booking);
        $rules = $this->config->businessRules();
        $preArrivalDays = $rules->documents->preArrivalDaysBefore;
        $voucherDays = $rules->documents->voucherDaysBefore;
        $reminderSlots = $rules->payments->balanceReminderDays;
        $today = BusinessTime::now()->toDateString();
        $checkIn = $booking->stay()->checkIn()->toDateString();
        $due = $booking->balanceDueDate()->toDateString();
        $confirmed = $booking->status->isConfirmedOrLater();
        $fullyPaid = in_array($booking->status, [
            BookingStatus::FullyPaid,
            BookingStatus::InHouse,
            BookingStatus::CheckedOut,
        ], true);
        $cruiseOpen = $this->cruiseOutstanding($booking) > 0;
        $rows = [];

        $rows[] = $this->documentRow(
            $booking,
            DocumentPlanKind::Invoice,
            'Deposit verified → CONFIRMED',
            $this->issuedDate($booking, DocumentKind::Invoice) ?? $this->depositDate($booking),
            $confirmed ? DocumentPlanStatus::Due : DocumentPlanStatus::Waiting,
            $canAct,
            DeliveryKind::Invoice,
        );

        $rows[] = $this->documentRow(
            $booking,
            DocumentPlanKind::Summary,
            'With the invoice',
            $this->issuedDate($booking, DocumentKind::Summary) ?? $this->depositDate($booking),
            $confirmed ? DocumentPlanStatus::Due : DocumentPlanStatus::Waiting,
            $canAct,
            DeliveryKind::Summary,
        );

        foreach ($this->settledPayments($booking) as $payment) {
            $rows[] = $this->receiptRow($booking, $payment, $canAct);
        }

        if ($cruiseOpen) {
            foreach ($reminderSlots as $index => $days) {
                $sendDate = BusinessTime::calendarDay($due)->subDays($days)->toDateString();
                $rows[] = $this->reminderRow(
                    $booking,
                    $days,
                    $index + 1,
                    $due,
                    $sendDate,
                    $today,
                    $confirmed || $booking->status === BookingStatus::OnHoldAgency,
                );
            }
        } else {
            $rows[] = $this->row(
                $booking,
                DocumentPlanKind::Reminder,
                'Balance reminders',
                $this->recipientLabel($booking, DeliveryKind::Reminder),
                'Balance paid',
                null,
                DocumentPlanStatus::NotNeeded,
                null,
                null,
                null,
                null,
                false,
                false,
                false,
            );
        }

        $preArrivalDate = BusinessTime::calendarDay($checkIn)->subDays($preArrivalDays)->toDateString();
        $rows[] = $this->documentRow(
            $booking,
            DocumentPlanKind::PreArrival,
            'T−'.$preArrivalDays,
            $preArrivalDate,
            $this->scheduleStatus($confirmed, $preArrivalDate, $today),
            $canAct,
            DeliveryKind::PreArrival,
        );

        $rows[] = $this->questionnaireRow($booking, $preArrivalDays, $preArrivalDate, $today, $confirmed);

        $hasVoucher = DocumentFacts::bookingHasTransferVoucher($booking);
        $voucherDate = BusinessTime::calendarDay($checkIn)->subDays($voucherDays)->toDateString();
        $rows[] = $this->documentRow(
            $booking,
            DocumentPlanKind::Voucher,
            'T−'.$voucherDays.' · if contracted',
            $hasVoucher ? $voucherDate : null,
            $hasVoucher
                ? $this->scheduleStatus($confirmed, $voucherDate, $today)
                : DocumentPlanStatus::NotContracted,
            $canAct && $hasVoucher,
            DeliveryKind::Voucher,
        );

        $rows[] = $this->documentRow(
            $booking,
            DocumentPlanKind::FinalInvoice,
            'Balance paid → FULLY PAID',
            $this->issuedDate($booking, DocumentKind::FinalInvoice) ?? $this->balancePaymentDate($booking),
            $fullyPaid ? DocumentPlanStatus::Due : DocumentPlanStatus::Waiting,
            $canAct,
            DeliveryKind::FinalInvoice,
        );

        $wire = $this->latestDocument($booking, DocumentKind::WireInstructions);

        if ($wire instanceof Document) {
            $rows[] = $this->documentRow(
                $booking,
                DocumentPlanKind::WireInstructions,
                'Sent by hand',
                $wire->issued_at->toDateString(),
                DocumentPlanStatus::Due,
                $canAct,
                DeliveryKind::WireInstructions,
            );
        }

        return $rows;
    }

    private function questionnaireRow(
        Booking $booking,
        int $pretripDays,
        string $pretripDate,
        string $today,
        bool $confirmed,
    ): DocumentPlanRow {
        $deliveries = $booking->deliveries
            ->where('kind', DeliveryKind::Questionnaire)
            ->sortByDesc('id')
            ->values();
        $fallback = $this->scheduleStatus($confirmed, $pretripDate, $today);
        $latest = $deliveries->first();

        return $this->row(
            $booking,
            DocumentPlanKind::Questionnaire,
            DocumentPlanKind::Questionnaire->label(),
            'Each passenger with email',
            'T−'.$pretripDays,
            $pretripDate,
            $this->questionnaireStatus($booking, $deliveries, $fallback),
            null,
            null,
            $latest?->id,
            $this->deliveryError($latest),
            false,
            false,
            false,
        );
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries
     */
    private function questionnaireStatus(
        Booking $booking,
        Collection $deliveries,
        DocumentPlanStatus $fallback,
    ): DocumentPlanStatus {
        if ($deliveries->isEmpty()) {
            if ($fallback !== DocumentPlanStatus::Waiting && ! $this->questionnaireCanSend($booking)) {
                return DocumentPlanStatus::Blocked;
            }

            return $fallback;
        }

        if ($deliveries->contains(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Failed)) {
            return DocumentPlanStatus::Failed;
        }

        if ($deliveries->contains(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Blocked)) {
            return DocumentPlanStatus::Blocked;
        }

        if ($deliveries->contains(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Queued)) {
            return DocumentPlanStatus::Due;
        }

        return DocumentPlanStatus::Sent;
    }

    private function questionnaireCanSend(Booking $booking): bool
    {
        $named = $booking->guests->contains(
            fn (Guest $guest): bool => trim($guest->first_name.$guest->last_name) !== ''
                || $this->recipients->usableAddress($guest->email) !== null,
        );

        if (! $named) {
            return false;
        }

        $hasGuestEmail = $booking->guests->contains(
            fn (Guest $guest): bool => $this->recipients->usableAddress($guest->email) !== null,
        );

        if ($hasGuestEmail) {
            return true;
        }

        return $this->recipients->resolve($booking, DeliveryKind::Questionnaire)->usable();
    }

    private function documentRow(
        Booking $booking,
        DocumentPlanKind $kind,
        string $trigger,
        ?string $date,
        DocumentPlanStatus $fallback,
        bool $canAct,
        DeliveryKind $deliveryKind,
    ): DocumentPlanRow {
        $document = $this->latestIssued($booking, $kind);
        $delivery = $document instanceof Document
            ? $this->lastDelivery($document->deliveries)
            : $this->lastBookingDelivery($booking, $deliveryKind);

        if (! $delivery instanceof Delivery && $kind === DocumentPlanKind::PreArrival) {
            $delivery = $this->lastBookingDelivery($booking, DeliveryKind::Pretrip);
        }
        $recipients = $this->recipients->resolve($booking, $deliveryKind);
        $status = $this->statusFromFacts($delivery, $recipients, $fallback);
        $documentKind = $kind->documentKind();
        $canPreview = $documentKind instanceof DocumentKind;
        $canIssue = $canAct && $documentKind instanceof DocumentKind && $kind !== DocumentPlanKind::Receipt;
        $canResend = $document instanceof Document && $delivery instanceof Delivery
            && in_array($delivery->status, [
                DeliveryStatus::Sent,
                DeliveryStatus::Failed,
            ], true);

        return $this->row(
            $booking,
            $kind,
            $kind->label(),
            $this->recipientLabel($booking, $deliveryKind),
            $trigger,
            $date,
            $status,
            $document?->id,
            $document?->version,
            $delivery?->id,
            $this->deliveryError($delivery),
            $canPreview,
            $canIssue,
            $canResend,
        );
    }

    private function receiptRow(Booking $booking, Payment $payment, bool $canAct): DocumentPlanRow
    {
        $document = $booking->documents
            ->first(fn (Document $document): bool => $document->kind === DocumentKind::Receipt
                && (int) $document->payment_id === (int) $payment->id);
        $delivery = $document instanceof Document
            ? $this->lastDelivery($document->deliveries)
            : null;
        $recipients = $this->recipients->resolve($booking, DeliveryKind::Receipt);
        $fallback = DocumentPlanStatus::Due;
        $status = $this->statusFromFacts($delivery, $recipients, $fallback);
        $date = $payment->paid_at->toDateString();

        return $this->row(
            $booking,
            DocumentPlanKind::Receipt,
            'Payment confirmation — '.$payment->kind->label(),
            $this->recipientLabel($booking, DeliveryKind::Receipt),
            'Payment verified',
            $date,
            $status,
            $document?->id,
            $document?->version,
            $delivery?->id,
            $this->deliveryError($delivery),
            true,
            false,
            $document instanceof Document && $delivery instanceof Delivery
                && in_array($delivery->status, [
                    DeliveryStatus::Sent,
                    DeliveryStatus::Failed,
                ], true),
            paymentId: $payment->id,
        );
    }

    private function reminderRow(
        Booking $booking,
        int $days,
        int $index,
        string $due,
        string $sendDate,
        string $today,
        bool $qualifies,
    ): DocumentPlanRow {
        $key = DeliveryKey::forReminder($booking->id, $due, $days);
        $delivery = $booking->deliveries->first(
            fn (Delivery $row): bool => $row->idempotency_key === $key
                || $row->idempotency_key === DeliveryKey::blocked($key),
        );
        $recipients = $this->recipients->resolve($booking, DeliveryKind::Reminder);
        $fallback = ! $qualifies
            ? DocumentPlanStatus::Waiting
            : ($sendDate > $today ? DocumentPlanStatus::Scheduled : DocumentPlanStatus::Due);
        $status = $this->statusFromFacts($delivery, $recipients, $fallback);

        return $this->row(
            $booking,
            DocumentPlanKind::Reminder,
            'Balance reminder '.$index.' ('.$days.' days before due)',
            $this->recipientLabel($booking, DeliveryKind::Reminder),
            'Due '.$due.' − '.$days.' days',
            $sendDate,
            $status,
            null,
            null,
            $delivery?->id,
            $this->deliveryError($delivery),
            false,
            false,
            false,
            reminderDays: $days,
        );
    }

    private function row(
        Booking $booking,
        DocumentPlanKind $kind,
        string $name,
        string $recipient,
        string $trigger,
        ?string $date,
        DocumentPlanStatus $status,
        ?int $documentId,
        ?int $version,
        ?int $deliveryId,
        ?string $error,
        bool $canPreview,
        bool $canIssue,
        bool $canResend,
        ?int $reminderDays = null,
        ?int $paymentId = null,
    ): DocumentPlanRow {
        return new DocumentPlanRow(
            $booking->id,
            $booking->displayReference(),
            $booking->contact->name,
            $kind,
            $name,
            $recipient,
            $trigger,
            $date,
            $status,
            $documentId,
            $version,
            $deliveryId,
            $error,
            $canPreview,
            $canIssue,
            $canResend,
            $reminderDays,
            $paymentId,
        );
    }

    private function deliveryError(?Delivery $delivery): ?string
    {
        if (! $delivery instanceof Delivery) {
            return null;
        }

        return $delivery->error ?? $delivery->blocked_reason;
    }

    private function statusFromFacts(
        ?Delivery $delivery,
        RecipientSet $recipients,
        DocumentPlanStatus $fallback,
    ): DocumentPlanStatus {
        if ($delivery instanceof Delivery) {
            return match ($delivery->status) {
                DeliveryStatus::Sent => DocumentPlanStatus::Sent,
                DeliveryStatus::Failed, DeliveryStatus::HardBounce => DocumentPlanStatus::Failed,
                DeliveryStatus::Blocked => DocumentPlanStatus::Blocked,
                DeliveryStatus::Queued => $fallback === DocumentPlanStatus::Waiting
                    ? DocumentPlanStatus::Due
                    : $fallback,
            };
        }

        if (! $recipients->usable()
            && ! in_array($fallback, [
                DocumentPlanStatus::Waiting,
                DocumentPlanStatus::NotNeeded,
                DocumentPlanStatus::NotContracted,
            ], true)
        ) {
            return DocumentPlanStatus::Blocked;
        }

        return $fallback;
    }

    private function scheduleStatus(bool $qualifies, string $date, string $today): DocumentPlanStatus
    {
        if (! $qualifies) {
            return DocumentPlanStatus::Waiting;
        }

        return $date > $today ? DocumentPlanStatus::Scheduled : DocumentPlanStatus::Due;
    }

    private function recipientLabel(Booking $booking, DeliveryKind $kind): string
    {
        $set = $this->recipients->resolve($booking, $kind);

        if (! $set->usable()) {
            return $set->blockedReason ?? 'No email address';
        }

        $to = implode(', ', $set->to);

        return $set->cc === [] ? $to : $to.' + '.implode(', ', $set->cc);
    }

    private function latestIssued(Booking $booking, DocumentPlanKind $kind): ?Document
    {
        $primary = $kind->documentKind();

        if (! $primary instanceof DocumentKind) {
            return null;
        }

        $document = $this->latestDocument($booking, $primary);

        if ($document instanceof Document || $kind !== DocumentPlanKind::PreArrival) {
            return $document;
        }

        return $this->latestDocument($booking, DocumentKind::Pretrip);
    }

    private function latestDocument(Booking $booking, DocumentKind $kind): ?Document
    {
        return $booking->documents
            ->where('kind', $kind)
            ->sortByDesc('version')
            ->first();
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries
     */
    private function lastDelivery(Collection $deliveries): ?Delivery
    {
        return $deliveries->sortByDesc('id')->first();
    }

    private function lastBookingDelivery(Booking $booking, DeliveryKind $kind): ?Delivery
    {
        return $booking->deliveries
            ->where('kind', $kind)
            ->sortByDesc('id')
            ->first();
    }

    private function issuedDate(Booking $booking, DocumentKind $kind): ?string
    {
        $document = $this->latestDocument($booking, $kind);

        return $document?->issued_at?->toDateString();
    }

    private function depositDate(Booking $booking): ?string
    {
        $deposit = $this->settledPayments($booking)
            ->first(fn (Payment $payment): bool => $payment->kind === PaymentKind::Deposit);

        return $deposit?->paid_at?->toDateString();
    }

    private function balancePaymentDate(Booking $booking): ?string
    {
        $balance = $this->settledPayments($booking)
            ->last(fn (Payment $payment): bool => $payment->kind === PaymentKind::Balance);

        return $balance?->paid_at?->toDateString();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function settledPayments(Booking $booking): Collection
    {
        return $booking->payments
            ->where('status', PaymentStatus::Settled)
            ->filter(fn (Payment $payment): bool => $payment->amount > 0)
            ->sortBy('id')
            ->values();
    }

    private function cruiseOutstanding(Booking $booking): int
    {
        $paid = (int) $booking->payments
            ->filter(fn (Payment $payment): bool => $payment->status->countsAsPaid())
            ->sum('amount');

        return max(0, $booking->total - $paid);
    }
}
