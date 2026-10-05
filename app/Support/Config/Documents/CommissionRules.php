<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class CommissionRules
{
    public function __construct(
        public int $capPct,
        public int $defaultPct,
        public int $payableDaysAfterCheckOut,
    ) {}

    /**
     * @return array{cap_pct: int, default_pct: int, payable_days_after_check_out: int}
     */
    public function toArray(): array
    {
        return [
            'cap_pct' => $this->capPct,
            'default_pct' => $this->defaultPct,
            'payable_days_after_check_out' => $this->payableDaysAfterCheckOut,
        ];
    }
}
