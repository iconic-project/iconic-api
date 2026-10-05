<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class LengthOfStayBand
{
    public function __construct(
        public int $minNights,
        public int $discountPct,
    ) {}

    /**
     * @return array{min_nights: int, discount_pct: int}
     */
    public function toArray(): array
    {
        return [
            'min_nights' => $this->minNights,
            'discount_pct' => $this->discountPct,
        ];
    }
}
