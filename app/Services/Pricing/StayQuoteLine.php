<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class StayQuoteLine
{
    /**
     * @param  array<string, int|string>  $params
     */
    public function __construct(
        public string $code,
        public string $key,
        public array $params,
        public int $amount,
    ) {}

    public function label(): string
    {
        return QuoteLabels::render($this->key, $this->params);
    }

    /**
     * @return array{code: string, label: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label(),
            'amount' => $this->amount,
        ];
    }
}
