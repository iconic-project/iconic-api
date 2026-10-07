<?php

declare(strict_types=1);

namespace App\Enums;

enum ReferenceType: string
{
    case Booking = 'booking';
    case Request = 'request';
    case Block = 'block';
    case Group = 'group';
    case Offer = 'offer';
    case Agency = 'agency';
    case Invoice = 'invoice';
    case Proposal = 'proposal';

    public function isYearly(): bool
    {
        return match ($this) {
            self::Booking, self::Request, self::Invoice, self::Proposal => true,
            default => false,
        };
    }

    public function padWidth(): int
    {
        return match ($this) {
            self::Booking, self::Request, self::Invoice, self::Proposal => 4,
            default => 3,
        };
    }

    public function scope(?int $year = null): string
    {
        return $this->isYearly()
            ? $this->value.':'.$year
            : $this->value;
    }

    public function format(int $value, ?int $year = null): string
    {
        $number = str_pad((string) $value, $this->padWidth(), '0', STR_PAD_LEFT);

        return match ($this) {
            self::Booking => "HTL-{$year}-{$number}",
            self::Request => "HTL-R-{$year}-{$number}",
            self::Block => "BLK-{$number}",
            self::Group => "GRP-{$number}",
            self::Offer => "OF-{$number}",
            self::Agency => "AG-{$number}",
            self::Invoice => "INV-{$year}-{$number}",
            self::Proposal => "CP-{$year}-{$number}",
        };
    }
}
