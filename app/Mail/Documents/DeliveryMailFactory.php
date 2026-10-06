<?php

declare(strict_types=1);

namespace App\Mail\Documents;

use App\Enums\BookingAccessTokenPurpose;
use App\Enums\DeliveryKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentLinkStatus;
use App\Mail\Journeys\JourneyMail;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Delivery;
use App\Models\PaymentLink;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Waitlist\WaitlistOfferCopy;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;

final class DeliveryMailFactory
{
    public static function make(Delivery $delivery, ?string $pdfBytes): Mailable
    {
        $delivery->loadMissing(['booking.property', 'booking.paymentLinks', 'booking.accessTokens', 'document']);

        return match ($delivery->kind) {
            DeliveryKind::Reminder => new ReminderMail(
                $delivery,
                $delivery->booking,
                self::reminderDays($delivery),
                self::openBalanceLink($delivery),
                self::completePageUrl($delivery->booking),
            ),
            DeliveryKind::PaymentLink => new PaymentLinkMail(
                $delivery,
                $delivery->booking,
                self::paymentLink($delivery),
                self::completePageUrl($delivery->booking),
            ),
            DeliveryKind::DataChaser => new DataChaserMail(
                $delivery,
                $delivery->booking,
                self::completePageUrl($delivery->booking),
            ),
            DeliveryKind::Questionnaire => new QuestionnaireMail(
                $delivery,
                $delivery->booking,
                self::questionnaireUrl($delivery),
            ),
            DeliveryKind::Survey => new SurveyMail(
                $delivery,
                $delivery->booking,
                self::surveyUrl($delivery),
            ),
            DeliveryKind::ReviewRequest => new ReviewRequestMail(
                $delivery,
                $delivery->booking,
                app(CurrentConfig::class)->businessRules()->nps->reviewUrl,
            ),
            DeliveryKind::WaitlistOffer => WaitlistOfferCopy::mail($delivery),
            DeliveryKind::CharterProposal => throw new InvalidArgumentException('Charter proposal mail has been removed.'),
            DeliveryKind::Journey => new JourneyMail($delivery),
            default => self::documentMail($delivery, $pdfBytes),
        };
    }

    private static function documentMail(Delivery $delivery, ?string $pdfBytes): DocumentMail
    {
        $document = $delivery->document;

        if ($document === null || $pdfBytes === null) {
            throw new InvalidArgumentException('A document delivery needs the stored PDF.');
        }

        return new DocumentMail($delivery, $document, $pdfBytes);
    }

    private static function reminderDays(Delivery $delivery): int
    {
        $due = $delivery->booking->balanceDueDate()->toDateString();
        $today = BusinessTime::now()->toDateString();

        return max(0, BusinessTime::calendarDaysBetween($today, $due));
    }

    private static function openBalanceLink(Delivery $delivery): ?PaymentLink
    {
        return $delivery->booking->paymentLinks
            ->first(fn (PaymentLink $link): bool => $link->kind === PaymentKind::Balance
                && $link->status === PaymentLinkStatus::Open);
    }

    private static function completePageUrl(Booking $booking): string
    {
        $url = $booking->accessTokens->first(
            fn (BookingAccessToken $token): bool => $token->purpose === BookingAccessTokenPurpose::Complete && $token->isActive(),
        )?->page_url;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('A complete-reservation link is missing for this delivery.');
        }

        return $url;
    }

    private static function paymentLink(Delivery $delivery): PaymentLink
    {
        $parts = explode(':', $delivery->idempotency_key);
        $linkId = $parts[0] === 'resend' ? ($parts[2] ?? null) : ($parts[1] ?? null);

        if (! is_numeric($linkId)) {
            throw new InvalidArgumentException('A payment-link delivery is missing its link id.');
        }

        $link = PaymentLink::query()->find((int) $linkId);

        if (! $link instanceof PaymentLink) {
            throw new InvalidArgumentException('The payment link for this delivery is gone.');
        }

        return $link;
    }

    private static function questionnaireUrl(Delivery $delivery): string
    {
        $parts = explode(':', $delivery->idempotency_key);
        $scope = $parts[0] === 'resend' ? ($parts[3] ?? '') : ($parts[2] ?? '');

        $token = $delivery->booking->accessTokens->first(
            function (BookingAccessToken $token) use ($scope): bool {
                if ($token->purpose !== BookingAccessTokenPurpose::Questionnaire || ! $token->isActive()) {
                    return false;
                }

                if ($scope === 'lead') {
                    return $token->guest_id === null;
                }

                return (string) $token->guest_id === $scope;
            },
        );

        if (! $token instanceof BookingAccessToken || $token->page_url === '') {
            throw new InvalidArgumentException('A questionnaire link is missing for this delivery.');
        }

        return $token->page_url;
    }

    private static function surveyUrl(Delivery $delivery): string
    {
        $parts = explode(':', $delivery->idempotency_key);
        $lead = ($parts[2] ?? '') === 'lead';
        $guestId = $parts[1] ?? '';

        $token = $delivery->booking->accessTokens->first(
            function (BookingAccessToken $token) use ($lead, $guestId): bool {
                if ($token->purpose !== BookingAccessTokenPurpose::Survey || ! $token->isActive()) {
                    return false;
                }

                if ($lead) {
                    return $token->guest_id === null;
                }

                return (string) $token->guest_id === $guestId;
            },
        );

        if (! $token instanceof BookingAccessToken || $token->page_url === '') {
            throw new InvalidArgumentException('A survey link is missing for this delivery.');
        }

        return $token->page_url;
    }
}
