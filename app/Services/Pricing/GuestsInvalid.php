<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class GuestsInvalid
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $errors,
    ) {}
}
