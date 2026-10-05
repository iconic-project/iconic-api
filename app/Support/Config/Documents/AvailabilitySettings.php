<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class AvailabilitySettings
{
    public function __construct(
        public int $lowAvailabilityThreshold,
    ) {}

    /**
     * @return array{low_availability_threshold: int}
     */
    public function toArray(): array
    {
        return [
            'low_availability_threshold' => $this->lowAvailabilityThreshold,
        ];
    }
}
