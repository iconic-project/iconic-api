<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class ReportsRules
{
    public function __construct(
        public int $retentionDays,
        public int $pickupDays,
    ) {}

    /**
     * @return array{retention_days: int, pickup_days: int}
     */
    public function toArray(): array
    {
        return [
            'retention_days' => $this->retentionDays,
            'pickup_days' => $this->pickupDays,
        ];
    }
}
