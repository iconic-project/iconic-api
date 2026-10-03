<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleGroup: string
{
    case PricingPayments = 'pricing_payments';
    case HoldsServiceLevels = 'holds_service_levels';
    case Cancellation = 'cancellation';
    case GuestsCapacity = 'guests_capacity';
    case DataRetention = 'data_retention';
    case Legal = 'legal';
    case Crm = 'crm';
    case Stay = 'stay';
    case StructuralLocked = 'structural_locked';

    public function label(): string
    {
        return match ($this) {
            self::PricingPayments => 'Pricing & payments',
            self::HoldsServiceLevels => 'Holds & service levels',
            self::Cancellation => 'Cancellation',
            self::GuestsCapacity => 'Guests & capacity',
            self::DataRetention => 'Data retention',
            self::Legal => 'Legal documents',
            self::Crm => 'CRM',
            self::Stay => 'Stay',
            self::StructuralLocked => 'Structural — locked',
        };
    }
}
