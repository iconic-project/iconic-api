<?php

declare(strict_types=1);

namespace App\Mail\Waitlist;

use App\Models\Delivery;
use App\Support\Documents\IssuerMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WaitlistOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Delivery $delivery,
        public string $sentence,
        public string $stayUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->delivery->to,
            subject: $this->delivery->subject,
            replyTo: [new Address(IssuerMail::replyTo())],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.waitlist.offer');
    }
}
