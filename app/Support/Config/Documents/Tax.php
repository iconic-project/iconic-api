<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\TaxBasis;

final readonly class Tax
{
    public function __construct(
        public string $code,
        public string $label,
        public TaxBasis $basis,
        public int $amount,
        public ?int $childExemptUnderAge,
        public bool $charged,
        public bool $shownInPricePanel,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $basis = TaxBasis::tryFrom(is_string($row['basis'] ?? null) ? $row['basis'] : '');
        $exempt = $row['child_exempt_under_age'] ?? null;

        return new self(
            is_string($row['code'] ?? null) ? $row['code'] : '',
            is_string($row['label'] ?? null) ? $row['label'] : '',
            $basis ?? TaxBasis::PerStay,
            (int) ($row['amount'] ?? 0),
            is_numeric($exempt) ? (int) $exempt : null,
            (bool) ($row['charged'] ?? false),
            (bool) ($row['shown_in_price_panel'] ?? false),
        );
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     basis: string,
     *     amount: int,
     *     child_exempt_under_age: int|null,
     *     charged: bool,
     *     shown_in_price_panel: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'basis' => $this->basis->value,
            'amount' => $this->amount,
            'child_exempt_under_age' => $this->childExemptUnderAge,
            'charged' => $this->charged,
            'shown_in_price_panel' => $this->shownInPricePanel,
        ];
    }
}
