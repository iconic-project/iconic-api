<?php

declare(strict_types=1);

namespace App\Mail\Documents;

use App\Enums\DeliveryKind;
use App\Models\Delivery;
use App\Models\Document;
use App\Support\Documents\IssuerMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

final class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Delivery $delivery,
        public Document $document,
        public string $pdfBytes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->delivery->to,
            cc: $this->delivery->cc,
            subject: $this->delivery->subject,
            replyTo: [new Address(IssuerMail::replyTo())],
        );
    }

    public function content(): Content
    {
        $view = match ($this->delivery->kind) {
            DeliveryKind::Invoice => 'mail.documents.invoice',
            DeliveryKind::FinalInvoice => 'mail.documents.final-invoice',
            DeliveryKind::Summary => 'mail.documents.summary',
            DeliveryKind::Receipt => 'mail.documents.receipt',
            DeliveryKind::Voucher => 'mail.documents.voucher',
            DeliveryKind::PreArrival, DeliveryKind::Pretrip => 'mail.documents.pretrip',
            DeliveryKind::WireInstructions => 'mail.documents.wire-instructions',
            DeliveryKind::DataChaser => throw new InvalidArgumentException('A data chaser is not a document.'),
            DeliveryKind::Questionnaire => throw new InvalidArgumentException('A questionnaire is not a document.'),
            DeliveryKind::Survey => throw new InvalidArgumentException('A survey is not a document.'),
            DeliveryKind::ReviewRequest => throw new InvalidArgumentException('A review request is not a document.'),
            DeliveryKind::WaitlistOffer => throw new InvalidArgumentException('A waitlist offer is not a document.'),
            DeliveryKind::CharterProposal => throw new InvalidArgumentException('A charter proposal is not a booking document.'),
            DeliveryKind::Journey => throw new InvalidArgumentException('A journey message is not a document.'),
            default => 'mail.documents.invoice',
        };

        return new Content(
            view: $view,
            with: [
                'delivery' => $this->delivery,
                'document' => $this->document,
                'booking' => $this->delivery->booking,
                'replyTo' => IssuerMail::replyTo(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = $this->document->kind->value.'-v'.$this->document->version.'.pdf';

        return [
            Attachment::fromData(fn (): string => $this->pdfBytes, $filename)
                ->withMime('application/pdf'),
        ];
    }
}
