<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class FeesSettings
{
    public function __construct(
        public bool $showInPricePanel,
        public string $footnote,
    ) {}

    /**
     * @return array{
     *     show_in_price_panel: bool,
     *     footnote: string
     * }
     */
    public function toArray(): array
    {
        return [
            'show_in_price_panel' => $this->showInPricePanel,
            'footnote' => $this->footnote,
        ];
    }
}
