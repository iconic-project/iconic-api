<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Enums\BookingAccessTokenPurpose;
use App\Enums\PaymentLinkStatus;
use App\Enums\TemplateVariable;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Contact;
use App\Models\MessageTemplateVersion;
use App\Models\PaymentLink;
use App\Services\Config\CurrentConfig;
use App\Support\Documents\IssuerMail;
use Illuminate\Support\Facades\View;

final class TemplateRenderer
{
    public function render(MessageTemplateVersion $version, Contact $contact, ?Booking $booking): RenderedTemplate
    {
        $values = $this->values($version->variables, $contact, $booking);
        $body = $version->body;
        $paragraphs = $this->filledStrings($body['paragraphs'] ?? [], $values);
        $list = $this->filledStrings($body['list'] ?? [], $values);
        $subject = $this->fill($version->subject, $values);
        $cta = is_array($body['cta'] ?? null) ? $body['cta'] : null;
        $ctaLabel = is_string($cta['label'] ?? null) ? $this->fill($cta['label'], $values) : null;
        $ctaKey = is_string($cta['link_key'] ?? null) ? $cta['link_key'] : null;
        $ctaUrl = $ctaKey !== null ? ($values[$ctaKey] ?? null) : null;
        $reference = $booking?->displayReference();

        $html = View::make('mail.templates.message', [
            'subject' => $subject,
            'paragraphs' => $paragraphs,
            'list' => $list,
            'ctaLabel' => $ctaLabel,
            'ctaUrl' => is_string($ctaUrl) && $ctaUrl !== '' ? $ctaUrl : null,
            'replyTo' => IssuerMail::replyTo(),
            'reference' => is_string($reference) && $reference !== '' ? $reference : null,
        ])->render();

        return new RenderedTemplate($version, $subject, $html);
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, string>
     */
    public function values(array $tokens, Contact $contact, ?Booking $booking): array
    {
        $values = [];

        foreach ($tokens as $token) {
            $known = TemplateVariable::tryFrom($token);

            if (! $known instanceof TemplateVariable) {
                throw new TemplateVariableException('Unknown template variable {{'.$token.'}}.');
            }

            $value = $this->resolve($known, $contact, $booking);

            if ($value === null || $value === '') {
                throw new TemplateVariableException('Could not resolve {{'.$token.'}} for this contact.');
            }

            $values[$token] = $value;
        }

        return $values;
    }

    private function resolve(TemplateVariable $variable, Contact $contact, ?Booking $booking): ?string
    {
        return match ($variable) {
            TemplateVariable::FirstName => $this->firstName($contact),
            TemplateVariable::BookingReference => $booking?->displayReference(),
            TemplateVariable::CheckIn => $this->checkIn($booking),
            TemplateVariable::CheckOut => $this->checkOut($booking),
            TemplateVariable::Nights => $booking instanceof Booking ? (string) $booking->nights : null,
            TemplateVariable::RoomType => $this->roomType($booking),
            TemplateVariable::PropertyName => $this->propertyName($booking),
            TemplateVariable::CheckInTime => app(CurrentConfig::class)->businessRules()->stay->checkInTime,
            TemplateVariable::CheckOutTime => app(CurrentConfig::class)->businessRules()->stay->checkOutTime,
            TemplateVariable::BalanceDueDate => $booking instanceof Booking ? $booking->balanceDueDate()->toDateString() : null,
            TemplateVariable::DepositLink => $this->depositLink($booking),
            TemplateVariable::CompleteLink => $this->completeLink($booking),
            TemplateVariable::UnsubscribeLink => UnsubscribeLink::for($contact),
        };
    }

    private function firstName(Contact $contact): ?string
    {
        $name = trim($contact->name);

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name);

        if ($parts === false || $parts[0] === '') {
            return null;
        }

        return $parts[0];
    }

    private function checkIn(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        return $booking->stay()->checkIn()->toDateString();
    }

    private function checkOut(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        return $booking->stay()->checkOut()->toDateString();
    }

    private function roomType(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        $booking->loadMissing('roomType');
        $name = $booking->roomType?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function propertyName(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        $booking->loadMissing('property');
        $name = $booking->property->name;

        return $name !== '' ? $name : null;
    }

    private function depositLink(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        $link = $booking->paymentLinks()
            ->where('status', PaymentLinkStatus::Open)
            ->orderByDesc('id')
            ->first();

        if (! $link instanceof PaymentLink || $link->url === '') {
            return null;
        }

        return $link->url;
    }

    private function completeLink(?Booking $booking): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        $token = $booking->accessTokens()
            ->where('purpose', BookingAccessTokenPurpose::Complete)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $token instanceof BookingAccessToken || $token->page_url === '') {
            return null;
        }

        return $token->page_url;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function filledStrings(mixed $lines, array $values): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $filled = [];

        foreach ($lines as $line) {
            if (is_string($line)) {
                $filled[] = $this->fill($line, $values);
            }
        }

        return $filled;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $text, array $values): string
    {
        $replaced = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/',
            function (array $match) use ($values): string {
                $token = $match[1];

                if (! array_key_exists($token, $values)) {
                    throw new TemplateVariableException('Unknown template variable {{'.$token.'}}.');
                }

                return $values[$token];
            },
            $text,
        );

        return is_string($replaced) ? $replaced : $text;
    }
}
