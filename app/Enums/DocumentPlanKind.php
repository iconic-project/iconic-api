<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentPlanKind: string
{
    case Invoice = 'INVOICE';
    case Summary = 'SUMMARY';
    case Receipt = 'RECEIPT';
    case Reminder = 'REMINDER';
    case PreArrival = 'PRE_ARRIVAL';
    case Questionnaire = 'QUESTIONNAIRE';
    case Voucher = 'VOUCHER';
    case FinalInvoice = 'FINAL_INVOICE';
    case WireInstructions = 'WIRE_INSTRUCTIONS';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Booking Confirmation & Invoice',
            self::Summary => 'Booking Summary (guest version)',
            self::Receipt => 'Payment Confirmation',
            self::Reminder => 'Balance reminder',
            self::PreArrival => 'Pre-arrival information',
            self::Questionnaire => 'Guest preferences questionnaire',
            self::Voucher => 'Transfer voucher',
            self::FinalInvoice => 'Final invoice',
            self::WireInstructions => 'Wire Instructions',
        };
    }

    public function documentKind(): ?DocumentKind
    {
        return match ($this) {
            self::Invoice => DocumentKind::Invoice,
            self::Summary => DocumentKind::Summary,
            self::Receipt => DocumentKind::Receipt,
            self::PreArrival => DocumentKind::PreArrival,
            self::Voucher => DocumentKind::Voucher,
            self::FinalInvoice => DocumentKind::FinalInvoice,
            self::WireInstructions => DocumentKind::WireInstructions,
            self::Reminder, self::Questionnaire => null,
        };
    }

    public function deliveryKind(): ?DeliveryKind
    {
        return match ($this) {
            self::Reminder => DeliveryKind::Reminder,
            self::Questionnaire => DeliveryKind::Questionnaire,
            default => $this->documentKind() !== null
                ? DeliveryKind::fromDocument($this->documentKind())
                : null,
        };
    }
}
