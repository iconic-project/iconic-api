<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final readonly class NightLine
{
    public function __construct(
        public string $night,
        public string $season,
        public int $base,
        public int $extras,
        public int $single,
        public int $dow,
        public int $supplements,
        public int $planAdjust,
        public int $total,
    ) {}

    /**
     * @return array{
     *     night: string,
     *     season: string,
     *     base: int,
     *     extras: int,
     *     single: int,
     *     dow: int,
     *     supplements: int,
     *     plan_adjust: int,
     *     total: int
     * }
     */
    public function toArray(): array
    {
        return [
            'night' => $this->night,
            'season' => $this->season,
            'base' => $this->base,
            'extras' => $this->extras,
            'single' => $this->single,
            'dow' => $this->dow,
            'supplements' => $this->supplements,
            'plan_adjust' => $this->planAdjust,
            'total' => $this->total,
        ];
    }
}
