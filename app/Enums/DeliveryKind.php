<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryKind: string
{
    case Invoice = 'INVOICE';
    case FinalInvoice = 'FINAL_INVOICE';
    case Summary = 'SUMMARY';
    case Receipt = 'RECEIPT';
    case Reminder = 'REMINDER';
    case Voucher = 'VOUCHER';
    case PreArrival = 'PRE_ARRIVAL';
    case Pretrip = 'PRETRIP';
    case PaymentLink = 'PAYMENT_LINK';
    case WireInstructions = 'WIRE_INSTRUCTIONS';
    case DataChaser = 'DATA_CHASER';
    case Questionnaire = 'QUESTIONNAIRE';
    case Survey = 'SURVEY';
    case ReviewRequest = 'REVIEW_REQUEST';
    case WaitlistOffer = 'WAITLIST_OFFER';
    case CharterProposal = 'CHARTER_PROPOSAL';
    case PortalInvite = 'PORTAL_INVITE';
    case Journey = 'JOURNEY';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Booking Confirmation & Invoice',
            self::FinalInvoice => 'Final Invoice',
            self::Summary => 'Booking Summary',
            self::Receipt => 'Payment Confirmation',
            self::Reminder => 'Balance reminder',
            self::Voucher => 'Transfer Voucher',
            self::PreArrival => 'Pre-arrival information',
            self::Pretrip => 'Pre-arrival plan',
            self::PaymentLink => 'Payment link',
            self::WireInstructions => 'Wire Instructions',
            self::DataChaser => 'Passenger details needed',
            self::Questionnaire => 'Guest preferences questionnaire',
            self::Survey => 'Post-trip survey',
            self::ReviewRequest => 'Review request',
            self::WaitlistOffer => 'Waitlist offer',
            self::CharterProposal => 'Charter proposal',
            self::PortalInvite => 'Portal invitation',
            self::Journey => 'Journey message',
        };
    }

    public function attachesPdf(): bool
    {
        return ! in_array($this, [
            self::Reminder,
            self::PaymentLink,
            self::DataChaser,
            self::Questionnaire,
            self::Survey,
            self::ReviewRequest,
            self::WaitlistOffer,
            self::CharterProposal,
            self::PortalInvite,
            self::Journey,
        ], true);
    }

    public function copiesAgency(): bool
    {
        return $this === self::Invoice || $this === self::FinalInvoice;
    }

    public function isDocumentKind(): bool
    {
        return $this->documentKind() !== null;
    }

    public function documentKind(): ?DocumentKind
    {
        return match ($this) {
            self::Invoice => DocumentKind::Invoice,
            self::FinalInvoice => DocumentKind::FinalInvoice,
            self::Summary => DocumentKind::Summary,
            self::Receipt => DocumentKind::Receipt,
            self::Voucher => DocumentKind::Voucher,
            self::PreArrival => DocumentKind::PreArrival,
            self::Pretrip => DocumentKind::Pretrip,
            self::WireInstructions => DocumentKind::WireInstructions,
            self::Reminder, self::PaymentLink, self::DataChaser, self::Questionnaire, self::Survey, self::ReviewRequest, self::WaitlistOffer, self::CharterProposal, self::PortalInvite, self::Journey => null,
        };
    }

    public static function fromDocument(DocumentKind $kind): self
    {
        return match ($kind) {
            DocumentKind::Invoice => self::Invoice,
            DocumentKind::FinalInvoice => self::FinalInvoice,
            DocumentKind::Summary => self::Summary,
            DocumentKind::Receipt => self::Receipt,
            DocumentKind::Voucher => self::Voucher,
            DocumentKind::PreArrival => self::PreArrival,
            DocumentKind::Pretrip => self::Pretrip,
            DocumentKind::WireInstructions => self::WireInstructions,
            DocumentKind::CharterProposal => self::CharterProposal,
        };
    }
}
