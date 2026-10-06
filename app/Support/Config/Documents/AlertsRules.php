<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class AlertsRules
{
    public function __construct(
        public int $lowOccupancyPct,
        public int $lowOccupancyDaysBefore,
        public int $lowOccupancyMinConsecutiveNights,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['low_occupancy_pct'] ?? 0),
            (int) ($data['low_occupancy_days_before'] ?? 0),
            (int) ($data['low_occupancy_min_consecutive_nights'] ?? 0),
        );
    }

    /**
     * @return array{low_occupancy_pct: int, low_occupancy_days_before: int, low_occupancy_min_consecutive_nights: int}
     */
    public function toArray(): array
    {
        return [
            'low_occupancy_pct' => $this->lowOccupancyPct,
            'low_occupancy_days_before' => $this->lowOccupancyDaysBefore,
            'low_occupancy_min_consecutive_nights' => $this->lowOccupancyMinConsecutiveNights,
        ];
    }
}
