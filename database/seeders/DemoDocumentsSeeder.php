<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Documents\PrepareIssueDocument;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Payment;
use App\Support\Documents\DeliveryKey;
use App\Support\Documents\DeliverySubject;
use App\Support\Documents\Recipients;
use Illuminate\Database\Seeder;

final class DemoDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $issuer = app(PrepareIssueDocument::class);
        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::FullyPaid])
            ->get();

        foreach ($bookings as $booking) {
            $this->issueOnce($issuer, $booking, DocumentKind::Invoice);
            $this->issueOnce($issuer, $booking, DocumentKind::Summary);
        }

        $payments = Payment::query()
            ->where('status', PaymentStatus::Settled)
            ->where('amount', '>', 0)
            ->get();

        foreach ($payments as $payment) {
            $issuer->handle(
                $payment->booking,
                DocumentKind::Receipt,
                payment: $payment,
                system: true,
            );
        }

        Document::query()
            ->with(['booking.contact', 'booking.group.coordinator', 'booking.agency', 'booking.guests', 'payment'])
            ->each(function (Document $document): void {
                $this->markSent($document);
            });
    }

    private function issueOnce(PrepareIssueDocument $issuer, Booking $booking, DocumentKind $kind): void
    {
        $exists = Document::query()
            ->where('booking_id', $booking->id)
            ->where('kind', $kind)
            ->exists();

        if ($exists) {
            return;
        }

        $issuer->handle($booking, $kind, system: true);
    }

    private function markSent(Document $document): void
    {
        $booking = $document->booking;
        $kind = DeliveryKind::fromDocument($document->kind);
        $recipients = app(Recipients::class)->resolve($booking, $kind);
        $firstKey = DeliveryKey::forDocument($document);
        $sentAt = $document->payment?->paid_at?->startOfDay()
            ?? $document->issued_at;
        $to = $recipients->usable()
            ? $recipients->to
            : $this->seedTo($booking);
        $cc = $recipients->usable() ? $recipients->cc : [];

        Delivery::query()->firstOrCreate(
            ['idempotency_key' => $firstKey],
            [
                'booking_id' => $booking->id,
                'document_id' => $document->id,
                'kind' => $kind,
                'to' => $to,
                'cc' => $cc,
                'subject' => DeliverySubject::forDocument($kind, $booking),
                'status' => DeliveryStatus::Sent,
                'triggered_by' => DeliveryTriggeredBy::System,
                'sent_at' => $sentAt,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function seedTo(Booking $booking): array
    {
        foreach ([$booking->billing_email, $booking->contact?->email] as $email) {
            if (is_string($email) && trim($email) !== '') {
                return [trim($email)];
            }
        }

        return ['seed@iconic.test'];
    }
}
