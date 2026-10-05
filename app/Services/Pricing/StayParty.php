<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Adults are counted. Each child is an age. Infant ages are not a separate category (09 H8).
 */
final readonly class StayParty
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public int $adults,
        public array $childAges,
    ) {}
}
