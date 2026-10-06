<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Actions\Documents\SendDocument;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Jobs\SendDeliveryJob;
use App\Models\ChangeHistory;
use App\Models\Delivery;
use App\Models\Document;
use App\Support\Documents\DeliveryKey;
use App\Support\History\History;
use Illuminate\Support\Facades\DB;

final class DocumentCheck
{
    public const REQUEUED = 'delivery.requeued';

    public function __construct(private readonly SendDocument $send) {}

    public function run(): void
    {
        foreach ($this->latestAutomatic() as $document) {
            $document->loadMissing(['deliveries', 'booking']);
            $deliveries = $document->deliveries;

            if ($deliveries->contains(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Sent)) {
                continue;
            }

            if ($deliveries->contains(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Queued)) {
                continue;
            }

            $failed = $deliveries->first(fn (Delivery $delivery): bool => $delivery->status === DeliveryStatus::Failed);

            if ($failed instanceof Delivery) {
                $this->requeueOnce($document, $failed);

                continue;
            }

            $this->send->handle(
                $document->booking,
                $document,
                system: true,
                idempotencyKey: DeliveryKey::forDocument($document),
            );
        }
    }

    /**
     * @return list<Document>
     */
    private function latestAutomatic(): array
    {
        $kinds = [
            DocumentKind::Invoice,
            DocumentKind::Summary,
            DocumentKind::Receipt,
            DocumentKind::FinalInvoice,
            DocumentKind::PreArrival,
            DocumentKind::Pretrip,
            DocumentKind::Voucher,
        ];

        return Document::query()
            ->whereIn('kind', $kinds)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (Document $document): string => $document->booking_id.'|'.$document->kind->value)
            ->values()
            ->all();
    }

    private function requeueOnce(Document $document, Delivery $delivery): void
    {
        if ($this->alreadyRequeued($document)) {
            return;
        }

        DB::transaction(function () use ($document, $delivery): void {
            $delivery->status = DeliveryStatus::Queued;
            $delivery->error = null;
            $delivery->save();

            History::record($document->booking, self::REQUEUED, after: [
                'document_id' => $document->id,
                'delivery_id' => $delivery->id,
                'idempotency_key' => $delivery->idempotency_key,
            ], system: true);

            SendDeliveryJob::dispatch($delivery->id)->afterCommit();
        });
    }

    private function alreadyRequeued(Document $document): bool
    {
        $booking = $document->booking;

        return ChangeHistory::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->where('event', self::REQUEUED)
            ->where('after->document_id', $document->id)
            ->exists();
    }
}
