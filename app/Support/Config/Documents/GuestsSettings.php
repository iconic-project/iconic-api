<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class GuestsSettings
{
    public function __construct(
        public int $maxPerCabin,
        public int $maxPerProperty,
        public int $childMinAge,
        public int $childMaxAge,
        public bool $adultRequiredWithChildren,
        public string $underAgeMessage,
    ) {}

    /**
     * @return array{
     *     max_per_cabin: int,
     *     max_per_property: int,
     *     child_min_age: int,
     *     child_max_age: int,
     *     adult_required_with_children: bool,
     *     under_age_message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'max_per_cabin' => $this->maxPerCabin,
            'max_per_property' => $this->maxPerProperty,
            'child_min_age' => $this->childMinAge,
            'child_max_age' => $this->childMaxAge,
            'adult_required_with_children' => $this->adultRequiredWithChildren,
            'under_age_message' => $this->underAgeMessage,
        ];
    }
}
