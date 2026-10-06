<?php

declare(strict_types=1);

namespace App\Enums;

enum OfferType: string
{
    case Credit = 'CREDIT';
    case Amount = 'AMT';
    case Percent = 'PCT';
    case Value = 'VALUE';
    case Commission = 'COMM';

    public function isPriceAffecting(): bool
    {
        return $this !== self::Value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Ancillary credit (USD per room)',
            self::Amount => 'Amount off (USD per room)',
            self::Percent => 'Percent off room rate',
            self::Value => 'Value-add (no price change)',
            self::Commission => 'Extra partner commission (%)',
        };
    }
}
