<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CabinCategory;

final readonly class QuoteInput
{
    public function __construct(
        public int $year,
        public QuoteType $type,
        // TODO(Sprint 18): room type pricing (09 H8)
        public ?CabinCategory $category = null,
        public int $adults = 0,
        public int $children = 0,
        public bool $festive = false,
        public bool $backToBack = false,
    ) {}
}
