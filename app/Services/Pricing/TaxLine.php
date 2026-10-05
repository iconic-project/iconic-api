<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class TaxLine
{
    /**
     * @param  array<string, int|string>  $params
     */
    public function __construct(
        public string $code,
        public string $key,
        public array $params,
        public int $amount,
        public bool $charged,
        public bool $shownInPricePanel,
    ) {}

    public function label(): string
    {
        return QuoteLabels::render($this->key, $this->params);
    }

    /**
     * @return array{code: string, label: string, amount: int, charged: bool, shown_in_price_panel: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label(),
            'amount' => $this->amount,
            'charged' => $this->charged,
            'shown_in_price_panel' => $this->shownInPricePanel,
        ];
    }
}
