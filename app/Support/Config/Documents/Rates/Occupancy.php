<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class Occupancy
{
    public function __construct(
        public int $extraAdultNightly,
        public int $extraChildNightly,
        public int $singleOccupancyPct,
    ) {}

    /**
     * @return array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int}
     */
    public function toArray(): array
    {
        return [
            'extra_adult_nightly' => $this->extraAdultNightly,
            'extra_child_nightly' => $this->extraChildNightly,
            'single_occupancy_pct' => $this->singleOccupancyPct,
        ];
    }
}
