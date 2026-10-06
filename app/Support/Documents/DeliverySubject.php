<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\DeliveryKind;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\PaymentLink;

final class DeliverySubject
{
    public static function forDocument(DeliveryKind $kind, Booking $booking): string
    {
        $ref = $booking->displayReference() ?? 'booking';

        return match ($kind) {
            DeliveryKind::Invoice => 'Booking confirmation & invoice — '.$ref,
            DeliveryKind::FinalInvoice => 'Final invoice — '.$ref,
            DeliveryKind::Summary => 'Your Iconic booking summary — '.$ref,
            DeliveryKind::Receipt => 'Payment confirmation — '.$ref,
            DeliveryKind::Voucher => 'Transfer voucher — '.$ref,
            DeliveryKind::PreArrival, DeliveryKind::Pretrip => 'Before you arrive — '.$ref,
            DeliveryKind::WireInstructions => 'Wire transfer instructions — '.$ref,
            DeliveryKind::Reminder, DeliveryKind::PaymentLink => $kind->label().' — '.$ref,
            DeliveryKind::DataChaser => 'Passenger details needed — '.$ref,
            DeliveryKind::Questionnaire => 'Your preferences questionnaire — '.$ref,
            DeliveryKind::Survey => 'Your post-trip survey — '.$ref,
            DeliveryKind::ReviewRequest => 'Would you share a review? — '.$ref,
            DeliveryKind::WaitlistOffer => 'A room is free',
            DeliveryKind::CharterProposal => 'Your Iconic charter proposal',
            DeliveryKind::PortalInvite => 'Set your Iconic portal password',
            DeliveryKind::Journey => 'A note from Iconic — '.$ref,
        };
    }

    public static function forReminder(Booking $booking): string
    {
        return 'Your Iconic balance — due '.$booking->balanceDueDate()->toDateString();
    }

    public static function forPaymentLink(Booking $booking, PaymentLink $link): string
    {
        $ref = $booking->displayReference() ?? 'booking';

        return match ($link->kind) {
            PaymentKind::Deposit => 'Complete your Iconic reservation — '.$ref,
            PaymentKind::Balance => 'Pay your Iconic balance — '.$ref,
            default => 'Iconic payment link — '.$ref,
        };
    }
}
