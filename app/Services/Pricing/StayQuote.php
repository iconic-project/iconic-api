<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class StayQuote
{
    /**
     * @param  list<NightLine>  $nightLines
     * @param  list<StayQuoteLine>  $lines
     * @param  list<TaxLine>  $taxLines
     */
    public function __construct(
        public array $nightLines,
        public array $lines,
        public int $total,
        public int $depositPct,
        public int $deposit,
        public ?int $ratesVersionId,
        public QuoteTerms $terms,
        public array $taxLines = [],
    ) {
        $charged = 0;

        foreach ($this->taxLines as $line) {
            if ($line->charged) {
                $charged += $line->amount;
            }
        }

        $this->totalIncludingChargedTaxes = $this->total + $charged;
    }

    public int $totalIncludingChargedTaxes;

    /**
     * @return array{
     *     night_lines: list<array{night: string, season: string, base: int, extras: int, single: int, dow: int, supplements: int, plan_adjust: int, total: int, discount: int}>,
     *     lines: list<array{code: string, label: string, amount: int}>,
     *     total: int,
     *     deposit_pct: int,
     *     deposit: int,
     *     rates_version_id: int|null,
     *     terms: array{balance_days: int, charter: array{deposit_pct: int, deposit_business_days: int, balance_days: int, dpng_manifest_days: int}|null, deposit_pct?: int, refundable?: bool, cancellation_set?: string},
     *     tax_lines: list<array{code: string, label: string, amount: int, charged: bool, shown_in_price_panel: bool}>,
     *     total_including_charged_taxes: int
     * }
     */
    public function toArray(): array
    {
        return [
            'night_lines' => array_map(
                fn (NightLine $line): array => $line->toArray(),
                $this->nightLines,
            ),
            'lines' => array_map(
                fn (StayQuoteLine $line): array => $line->toArray(),
                $this->lines,
            ),
            'total' => $this->total,
            'deposit_pct' => $this->depositPct,
            'deposit' => $this->deposit,
            'rates_version_id' => $this->ratesVersionId,
            'terms' => $this->terms->toArray(),
            'tax_lines' => array_map(
                fn (TaxLine $line): array => $line->toArray(),
                $this->taxLines,
            ),
            'total_including_charged_taxes' => $this->totalIncludingChargedTaxes,
        ];
    }

    /**
     * @param  list<TaxLine>  $taxLines
     */
    public function withTaxes(array $taxLines): self
    {
        return new self(
            $this->nightLines,
            $this->lines,
            $this->total,
            $this->depositPct,
            $this->deposit,
            $this->ratesVersionId,
            $this->terms,
            $taxLines,
        );
    }

    public function withTerms(QuoteTerms $terms): self
    {
        return new self(
            $this->nightLines,
            $this->lines,
            $this->total,
            $this->depositPct,
            $this->deposit,
            $this->ratesVersionId,
            $terms,
            $this->taxLines,
        );
    }
}
