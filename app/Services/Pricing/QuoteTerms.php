<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BookingType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\CancellationBand;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Payments\CancellationPenalty;
use InvalidArgumentException;

final readonly class QuoteTerms
{
    /**
     * @param  array{deposit_pct: int, deposit_business_days: int, balance_days: int, dpng_manifest_days: int}|null  $charter
     * @param  list<CancellationBand>  $bands
     */
    public function __construct(
        public int $balanceDays,
        public ?array $charter,
        public ?int $depositPct = null,
        public ?bool $refundable = null,
        public ?string $cancellationSet = null,
        public array $bands = [],
    ) {}

    public static function fromConfig(CurrentConfig $config, BookingType $type): self
    {
        $rates = $config->rates()->terms;

        if ($type === BookingType::Charter) {
            return new self(
                balanceDays: $rates->charterBalanceDays,
                charter: [
                    'deposit_pct' => $rates->charterDepositPct,
                    'deposit_business_days' => $rates->charterDepositBusinessDays,
                    'balance_days' => $rates->charterBalanceDays,
                    'dpng_manifest_days' => $config->businessRules()->manifests->dpngCharterDays,
                ],
            );
        }

        return new self(
            balanceDays: $rates->cabinBalanceDays,
            charter: null,
        );
    }

    /**
     * Stay terms come from the plan. Pass the plan's cancellation set when a penalty is needed.
     *
     * @param  list<CancellationBand>  $bands
     */
    public static function forPlan(RatePlan $plan, array $bands = []): self
    {
        return new self(
            balanceDays: $plan->balanceDays,
            charter: null,
            depositPct: $plan->depositPct,
            refundable: $plan->refundable,
            cancellationSet: $plan->cancellation,
            bands: $bands,
        );
    }

    /**
     * Non-refundable plans ignore the set and charge 100% at every band.
     */
    public function penaltyPct(int $daysBeforeCheckIn): int
    {
        if ($this->refundable === false) {
            return 100;
        }

        if ($this->bands === []) {
            throw new InvalidArgumentException('Cancellation bands are required for a refundable plan.');
        }

        return CancellationPenalty::bandFor($daysBeforeCheckIn, $this->bands)['penalty_pct'];
    }

    public function cancellationSummary(): string
    {
        if ($this->refundable === false) {
            return 'Non-refundable';
        }

        return (string) $this->cancellationSet;
    }

    /**
     * @return array{
     *     balance_days: int,
     *     charter: array{deposit_pct: int, deposit_business_days: int, balance_days: int, dpng_manifest_days: int}|null,
     *     deposit_pct?: int,
     *     refundable?: bool,
     *     cancellation_set?: string
     * }
     */
    public function toArray(): array
    {
        $terms = [
            'balance_days' => $this->balanceDays,
            'charter' => $this->charter,
        ];

        if ($this->depositPct === null) {
            return $terms;
        }

        $terms['deposit_pct'] = $this->depositPct;
        $terms['refundable'] = $this->refundable === true;
        $terms['cancellation_set'] = (string) $this->cancellationSet;

        return $terms;
    }
}
