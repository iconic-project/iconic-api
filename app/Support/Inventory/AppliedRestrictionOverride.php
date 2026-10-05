<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * A staff override of sell rules. The reason is the history text.
 * The codes are the failures that were waived.
 */
final readonly class AppliedRestrictionOverride
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(
        public string $reason,
        public array $codes,
    ) {}
}
