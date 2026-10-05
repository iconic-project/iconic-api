<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * Every sell-rule failure for one stay, in the stable canBook order.
 */
final readonly class RestrictionResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public array $reasons,
    ) {}

    public function ok(): bool
    {
        return $this->reasons === [];
    }
}
