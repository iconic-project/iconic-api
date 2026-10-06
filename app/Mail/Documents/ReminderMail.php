<?php

declare(strict_types=1);

namespace App\Mail\Documents;

use App\Models\Booking;
use App\Models\Delivery;
use App\Models\PaymentLink;
use App\Support\Documents\IssuerMail;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Delivery $delivery,
        public Booking $booking,
        public int $days,
        public ?PaymentLink $payLink,
        public string $completeUrl,
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
        return new Content(
            view: 'mail.documents.reminder',
            with: [
                'delivery' => $this->delivery,
                'booking' => $this->booking,
                'days' => $this->days,
                'dueDate' => $this->booking->balanceDueDate()->toDateString(),
                'balance' => Money::format($this->booking->stayOutstanding()),
                'payUrl' => $this->completeUrl,
                'replyTo' => IssuerMail::replyTo(),
            ],
        );
    }
}
