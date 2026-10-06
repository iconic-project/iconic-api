<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Action;
use App\Enums\DocumentKind;
use App\Enums\ReferenceType;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Services\Documents\DocumentView;
use App\Services\Documents\PdfRenderer;
use App\Services\References\ReferenceService;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;
use App\Support\Iso;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IssueDocument extends Action
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ReferenceService $references,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(
        Booking $booking,
        DocumentKind $kind,
        array $snapshot,
        ?string $reason = null,
        ?Payment $payment = null,
        ?User $actor = null,
        bool $system = false,
    ): Document {
        $path = null;
        $disk = Storage::disk('documents');

        try {
            return $this->transaction(function () use (
                $booking,
                $kind,
                $snapshot,
                $reason,
                $payment,
                $actor,
                $system,
                $disk,
                &$path,
            ): Document {
                $locked = BookingMutationLock::acquire($booking);

                if ($kind->isReceipt()) {
                    if (! $payment instanceof Payment) {
                        throw ValidationException::withMessages([
                            'payment' => ['A receipt needs a payment.'],
                        ]);
                    }

                    if ((int) $payment->booking_id !== (int) $locked->id) {
                        throw ValidationException::withMessages([
                            'payment' => ['The payment does not belong to this booking.'],
                        ]);
                    }

                    $existing = Document::query()
                        ->where('payment_id', $payment->id)
                        ->where('kind', $kind)
                        ->first();

                    if ($existing instanceof Document) {
                        return $existing;
                    }
                }

                $versionQuery = Document::query()
                    ->where('booking_id', $locked->id)
                    ->where('kind', $kind);

                if ($kind->isReceipt()) {
                    $versionQuery->where('payment_id', $payment->id);
                }

                $version = (int) $versionQuery->max('version') + 1;

                if ($kind->isReceipt() && $version > 1) {
                    throw ValidationException::withMessages([
                        'kind' => ['Receipts are never re-versioned. A correction is a new payment with its own receipt.'],
                    ]);
                }

                if ($version > 1 && ($reason === null || $reason === '')) {
                    throw ValidationException::withMessages([
                        'reason' => ['A reason is required to re-issue a document.'],
                    ]);
                }

                $number = null;

                if ($kind->isNumbered()) {
                    if ($version === 1) {
                        $number = $this->references->next(ReferenceType::Invoice);
                    } else {
                        $first = Document::query()
                            ->where('booking_id', $locked->id)
                            ->where('kind', $kind)
                            ->where('version', 1)
                            ->firstOrFail();
                        $number = $first->number;
                    }
                }

                $issuedAt = now();
                $snapshot['document'] = array_merge(
                    is_array($snapshot['document'] ?? null) ? $snapshot['document'] : [],
                    [
                        'kind' => $kind->value,
                        'number' => $number,
                        'version' => $version,
                        'issued_at' => Iso::utc($issuedAt),
                    ],
                );

                $html = view(DocumentView::name($kind), ['snapshot' => $snapshot])->render();
                $bytes = $this->pdf->render($html);
                $path = $this->filePath($locked->id, $kind, $version, $payment);
                $disk->put($path, $bytes);

                $document = new Document;
                $document->booking_id = $locked->id;
                $document->kind = $kind;
                $document->number = $number;
                $document->version = $version;
                $document->reason = $version > 1 ? $reason : null;
                $document->payment_id = $kind->isReceipt() ? $payment->id : null;
                $document->snapshot = $snapshot;
                $document->file_path = $path;
                $document->file_sha256 = hash('sha256', $bytes);
                $document->issued_at = $issuedAt;
                $document->issued_by = $system ? null : ($actor instanceof User ? $actor->id : null);
                $document->save();

                $what = $kind->label().' issued — v'.$version;
                if ($version > 1) {
                    $what .= ': '.$reason;
                }

                History::record($locked, 'document.issued', after: [
                    'kind' => $kind->value,
                    'version' => $version,
                    'number' => $number,
                    'file_sha256' => $document->file_sha256,
                ], reason: $reason, actor: $actor, system: $system, extraContext: [
                    'what' => $what,
                ]);

                return $document;
            });
        } catch (Throwable $e) {
            if (is_string($path)) {
                $disk->delete($path);
            }

            throw $e;
        }
    }

    private function filePath(int $bookingId, DocumentKind $kind, int $version, ?Payment $payment): string
    {
        if ($kind->isReceipt()) {
            return sprintf('%d/RECEIPT-%d-v%d.pdf', $bookingId, (int) $payment?->id, $version);
        }

        return sprintf('%d/%s-v%d.pdf', $bookingId, $kind->value, $version);
    }
}
