<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\ConfigKind;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\RoomPricer;
use App\Services\Pricing\StayQuote;
use App\Services\Pricing\StayQuoteInput;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\Rates\Season;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;

/**
 * One RoomPricer call per season, room type and party.
 * Every night in that season reuses the first night that was priced.
 * Day-of-week and supplement differences inside the season are not recomputed.
 */
final class SeasonNightly
{
    /** @var array<string, int|null> */
    private array $cache = [];

    public function __construct(
        private readonly CurrentConfig $config,
        private readonly RoomPricer $pricer,
    ) {}

    /**
     * Lowest 1-night default-plan total in [from, to).
     *
     * @param  list<int>  $childAges
     */
    public function minimum(RoomType $type, CarbonImmutable $from, CarbonImmutable $to, int $adults, array $childAges): ?int
    {
        $min = null;

        foreach ($this->rates()->seasons as $season) {
            $sample = $this->sample($season, $from, $to);

            if ($sample === null) {
                continue;
            }

            $price = $this->price($type, $sample, $adults, $childAges);

            if ($price === null) {
                continue;
            }

            $min = $min === null ? $price : min($min, $price);
        }

        return $min;
    }

    /**
     * @param  list<int>  $childAges
     */
    public function price(RoomType $type, string $night, int $adults, array $childAges): ?int
    {
        $season = $this->seasonOn($night);

        if (! $season instanceof Season) {
            return null;
        }

        $key = $type->id.'|'.$season->code.'|'.$adults.'|'.implode(',', $childAges);

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->quote($type, $night, $adults, $childAges);
        }

        return $this->cache[$key];
    }

    public function defaultPlan(): string
    {
        foreach ($this->rates()->ratePlans as $plan) {
            if ($plan->isDefault) {
                return $plan->code;
            }
        }

        $first = $this->rates()->ratePlans[0] ?? null;

        return $first instanceof RatePlan ? $first->code : '';
    }

    /**
     * @return list<RatePlan>
     */
    public function plans(): array
    {
        return $this->rates()->ratePlans;
    }

    public function ratesVersionId(): int
    {
        return (int) $this->config->version(ConfigKind::Rates)->id;
    }

    public function rates(): RatesDocument
    {
        return $this->config->rates();
    }

    /**
     * @param  list<int>  $childAges
     */
    private function quote(RoomType $type, string $night, int $adults, array $childAges): ?int
    {
        $plan = $this->defaultPlan();

        if ($plan === '') {
            return null;
        }

        $priced = $this->pricer->quote($this->rates(), $type, new StayQuoteInput(
            StayDates::forNights($night, 1),
            $type->code,
            $adults,
            $childAges,
            $plan,
            null,
            $this->ratesVersionId(),
            false,
        ));

        return $priced instanceof StayQuote ? $priced->total : null;
    }

    private function seasonOn(string $night): ?Season
    {
        foreach ($this->rates()->seasons as $season) {
            if ($season->contains($night)) {
                return $season;
            }
        }

        return null;
    }

    private function sample(Season $season, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $sample = max($season->from, $from->toDateString());
        $until = $to->toDateString();

        if ($sample > $season->to || $sample >= $until) {
            return null;
        }

        return $sample;
    }
}
