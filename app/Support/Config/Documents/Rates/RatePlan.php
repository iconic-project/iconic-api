<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

final readonly class RatePlan
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isDefault,
        public int $adjustPct,
        public bool $refundable,
        public int $depositPct,
        public int $balanceDays,
        public string $cancellation,
        public string $mealPlan,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     default: bool,
     *     adjust_pct: int,
     *     refundable: bool,
     *     deposit_pct: int,
     *     balance_days: int,
     *     cancellation: string,
     *     meal_plan: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'default' => $this->isDefault,
            'adjust_pct' => $this->adjustPct,
            'refundable' => $this->refundable,
            'deposit_pct' => $this->depositPct,
            'balance_days' => $this->balanceDays,
            'cancellation' => $this->cancellation,
            'meal_plan' => $this->mealPlan,
        ];
    }
}
