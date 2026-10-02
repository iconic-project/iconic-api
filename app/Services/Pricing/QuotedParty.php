<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Room;

final readonly class QuotedParty
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $cabinCode,
        public string $cabinLabel,
        public int $adults,
        public int $children,
        public bool $available,
        public ?Quote $quote,
        public array $errors,
        public array $warnings,
        public ?Room $cabin = null,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
