<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Services\Config\CurrentConfig;
use App\Services\Config\StayConfigChecks;
use App\Support\Config\Change;
use App\Support\Config\ConfigDocument;
use App\Support\Config\DocumentDiff;
use App\Support\Config\Documents\Rates\DayOfWeek;
use App\Support\Config\Documents\Rates\DayOfWeekKeys;
use App\Support\Config\Documents\Rates\ExactlyOneDefaultRatePlan;
use App\Support\Config\Documents\Rates\KnownSeason;
use App\Support\Config\Documents\Rates\LengthOfStayBand;
use App\Support\Config\Documents\Rates\Occupancy;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\Rates\RatesV2;
use App\Support\Config\Documents\Rates\RoomRate;
use App\Support\Config\Documents\Rates\Season;
use App\Support\Config\Documents\Rates\SeasonsDoNotOverlap;
use App\Support\Config\Documents\Rates\Supplement;
use App\Support\Config\Documents\Rates\UniqueRoomRates;
use App\Support\Config\Warning;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use RuntimeException;

final class RatesDocument extends ConfigDocument
{
    /**
     * @param  list<RateYear>  $years
     * @param  list<Season>  $seasons
     * @param  list<RoomRate>  $roomRates
     * @param  list<LengthOfStayBand>  $lengthOfStay
     * @param  list<Supplement>  $supplements
     * @param  list<RatePlan>  $ratePlans
     */
    public function __construct(
        public readonly string $currency,
        public readonly int $schemaVersion,
        public readonly array $years,
        public readonly RateTerms $terms,
        public readonly RateRules $rules,
        public readonly array $seasons,
        public readonly array $roomRates,
        public readonly Occupancy $occupancy,
        public readonly DayOfWeek $dayOfWeek,
        public readonly array $lengthOfStay,
        public readonly array $supplements,
        public readonly array $ratePlans,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function initial(): array
    {
        return self::fromArray(self::source())->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $years = [];

        foreach ($data['years'] ?? [] as $year) {
            if (! is_array($year)) {
                continue;
            }

            $years[] = new RateYear(
                (int) ($year['year'] ?? 0),
                (int) ($year['suite_pp'] ?? 0),
                (int) ($year['owner_pp'] ?? 0),
                (int) ($year['charter_week'] ?? 0),
            );
        }

        $terms = is_array($data['terms'] ?? null) ? $data['terms'] : [];
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $occupancy = is_array($data['occupancy'] ?? null) ? $data['occupancy'] : [];
        $days = is_array($data['day_of_week'] ?? null) ? $data['day_of_week'] : [];

        return new self(
            (string) ($data['currency'] ?? ''),
            (int) ($data['schema_version'] ?? 0),
            $years,
            new RateTerms(
                (int) ($terms['cabin_deposit_pct'] ?? 0),
                (int) ($terms['cabin_balance_days'] ?? 0),
                (int) ($terms['charter_deposit_pct'] ?? 0),
                (int) ($terms['charter_deposit_business_days'] ?? 0),
                (int) ($terms['charter_balance_days'] ?? 0),
            ),
            new RateRules(
                (int) ($rules['single_supplement_pct'] ?? 0),
                (int) ($rules['triple_discount_pct'] ?? 0),
                (int) ($rules['child_discount_pct'] ?? 0),
                (int) ($rules['child_discounts_per_adult'] ?? 0),
                (int) ($rules['child_discounts_per_cabin'] ?? 0),
                (int) ($rules['back_to_back_pct'] ?? 0),
                (int) ($rules['festive_supplement_pp'] ?? 0),
                (int) ($rules['festive_supplement_charter'] ?? 0),
            ),
            self::seasonsFrom($data['seasons'] ?? []),
            self::roomRatesFrom($data['room_rates'] ?? []),
            new Occupancy(
                (int) ($occupancy['extra_adult_nightly'] ?? 0),
                (int) ($occupancy['extra_child_nightly'] ?? 0),
                (int) ($occupancy['single_occupancy_pct'] ?? 0),
            ),
            DayOfWeek::fromArray($days),
            self::lengthOfStayFrom($data['length_of_stay'] ?? []),
            self::supplementsFrom($data['supplements'] ?? []),
            self::ratePlansFrom($data['rate_plans'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'schema_version' => $this->schemaVersion,
            'years' => array_map(
                fn (RateYear $year): array => $year->toArray(),
                $this->years,
            ),
            'terms' => $this->terms->toArray(),
            'rules' => $this->rules->toArray(),
            'seasons' => array_map(
                fn (Season $season): array => $season->toArray(),
                $this->seasons,
            ),
            'room_rates' => array_map(
                fn (RoomRate $rate): array => $rate->toArray(),
                $this->roomRates,
            ),
            'occupancy' => $this->occupancy->toArray(),
            'day_of_week' => $this->dayOfWeek->toArray(),
            'length_of_stay' => array_map(
                fn (LengthOfStayBand $band): array => $band->toArray(),
                $this->lengthOfStay,
            ),
            'supplements' => array_map(
                fn (Supplement $supplement): array => $supplement->toArray(),
                $this->supplements,
            ),
            'rate_plans' => array_map(
                fn (RatePlan $plan): array => $plan->toArray(),
                $this->ratePlans,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in(['USD'])],
            'schema_version' => ['required', 'integer', Rule::in([RatesV2::SCHEMA_VERSION])],
            'years' => ['required', 'array', 'min:1', self::yearsAscending()],
            'years.*.year' => ['required', 'integer', 'distinct', 'min:2020', 'max:2100'],
            'years.*.suite_pp' => ['required', 'integer', 'min:1'],
            'years.*.owner_pp' => ['required', 'integer', 'min:1'],
            'years.*.charter_week' => ['required', 'integer', 'min:1'],
            'terms' => ['required', 'array'],
            'terms.cabin_deposit_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'terms.cabin_balance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'terms.charter_deposit_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'terms.charter_deposit_business_days' => ['required', 'integer', 'min:1', 'max:30'],
            'terms.charter_balance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'rules' => ['required', 'array'],
            'rules.single_supplement_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'rules.triple_discount_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'rules.child_discount_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'rules.child_discounts_per_adult' => ['required', 'integer', 'min:0', 'max:3'],
            'rules.child_discounts_per_cabin' => ['required', 'integer', 'min:0', 'max:3'],
            'rules.back_to_back_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'rules.festive_supplement_pp' => ['required', 'integer', 'min:0'],
            'rules.festive_supplement_charter' => ['required', 'integer', 'min:0'],
            'seasons' => ['present', 'array', self::seasonBounds(), new SeasonsDoNotOverlap],
            'seasons.*.code' => ['required', 'string', 'min:1', 'max:16', 'distinct'],
            'seasons.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'seasons.*.from' => ['required', 'date_format:Y-m-d'],
            'seasons.*.to' => ['required', 'date_format:Y-m-d'],
            'room_rates' => ['present', 'array', new UniqueRoomRates],
            'room_rates.*.room_type' => [
                'required',
                'string',
                Rule::exists('room_types', 'code')->where('status', RoomTypeStatus::Active->value),
            ],
            'room_rates.*.season' => ['required', 'string', new KnownSeason],
            'room_rates.*.nightly' => ['required', 'integer', 'min:1'],
            'occupancy' => ['required', 'array'],
            'occupancy.extra_adult_nightly' => ['required', 'integer', 'min:0'],
            'occupancy.extra_child_nightly' => ['required', 'integer', 'min:0'],
            'occupancy.single_occupancy_pct' => ['required', 'integer', 'min:-100', 'max:100'],
            'day_of_week' => ['present', 'array', new DayOfWeekKeys],
            'day_of_week.1' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.2' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.3' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.4' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.5' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.6' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'day_of_week.7' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'length_of_stay' => ['present', 'array', self::lengthOfStayAscending()],
            'length_of_stay.*.min_nights' => ['required', 'integer', 'min:2', 'distinct'],
            'length_of_stay.*.discount_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'supplements' => ['present', 'array', self::supplementBounds()],
            'supplements.*.code' => ['required', 'string', 'min:1', 'max:16', 'distinct'],
            'supplements.*.label' => ['required', 'string', 'min:1', 'max:80'],
            'supplements.*.from' => ['required', 'date_format:Y-m-d'],
            'supplements.*.to' => ['required', 'date_format:Y-m-d'],
            'supplements.*.per_night' => ['required', 'integer', 'min:0'],
            'supplements.*.basis' => ['required', 'string', Rule::in(['ROOM', 'PERSON'])],
            'rate_plans' => ['present', 'array', new ExactlyOneDefaultRatePlan],
            'rate_plans.*.code' => ['required', 'string', 'min:1', 'max:16', 'distinct'],
            'rate_plans.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'rate_plans.*.default' => ['required', 'boolean'],
            'rate_plans.*.adjust_pct' => ['required', 'integer', 'min:-100', 'max:100'],
            'rate_plans.*.refundable' => ['required', 'boolean'],
            'rate_plans.*.deposit_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'rate_plans.*.balance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'rate_plans.*.cancellation' => ['required', 'string', 'min:1', 'max:40'],
            // TODO(OPEN: 09 H8) Meal plan codes RO, BB, HB and FB are industry abbreviations. 09 does not list them — confirm the labels with the client.
            'rate_plans.*.meal_plan' => ['required', 'string', Rule::in(['RO', 'BB', 'HB', 'FB'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'currency' => 'Currency',
            'schema_version' => 'Schema version',
            'years' => 'Legacy (yacht) years',
            'terms.cabin_deposit_pct' => 'Cabin deposit %',
            'terms.cabin_balance_days' => 'Cabin balance — days before',
            'terms.charter_deposit_pct' => 'Charter deposit %',
            'terms.charter_deposit_business_days' => 'Charter deposit — business days',
            'terms.charter_balance_days' => 'Charter balance — days before',
            'rules.single_supplement_pct' => 'Legacy (yacht) single supplement %',
            'rules.triple_discount_pct' => 'Legacy (yacht) triple sharing discount %',
            'rules.child_discount_pct' => 'Legacy (yacht) child discount %',
            'rules.child_discounts_per_adult' => 'Legacy (yacht) child discounts per adult',
            'rules.child_discounts_per_cabin' => 'Legacy (yacht) child discounts per cabin',
            'rules.back_to_back_pct' => 'Legacy (yacht) back-to-back discount %',
            'rules.festive_supplement_pp' => 'Legacy (yacht) festive supplement / guest',
            'rules.festive_supplement_charter' => 'Legacy (yacht) festive supplement / charter',
            'seasons' => 'Seasons',
            'room_rates' => 'Room rates',
            'occupancy.extra_adult_nightly' => 'Extra adult / night',
            'occupancy.extra_child_nightly' => 'Extra child / night',
            'occupancy.single_occupancy_pct' => 'Single occupancy %',
            'day_of_week.1' => 'Monday adjustment %',
            'day_of_week.2' => 'Tuesday adjustment %',
            'day_of_week.3' => 'Wednesday adjustment %',
            'day_of_week.4' => 'Thursday adjustment %',
            'day_of_week.5' => 'Friday adjustment %',
            'day_of_week.6' => 'Saturday adjustment %',
            'day_of_week.7' => 'Sunday adjustment %',
            'length_of_stay' => 'Length of stay',
            'supplements' => 'Supplements',
            'rate_plans' => 'Rate plans',
        ];
    }

    public static function kind(): ConfigKind
    {
        return ConfigKind::Rates;
    }

    /**
     * @return list<Warning>
     */
    public function warnings(?ConfigDocument $published): array
    {
        $warnings = [];
        $publishedYears = $published instanceof self ? $published->yearsByYear() : [];

        foreach ($this->years as $index => $year) {
            $previous = $index > 0 ? $this->years[$index - 1] : null;

            foreach (self::priceFields() as $field => $label) {
                $value = $year->price($field);
                $path = 'years.'.$index.'.'.self::fieldKey($field);

                if ($previous instanceof RateYear && $value < $previous->price($field)) {
                    $warnings[] = new Warning(
                        $path,
                        $label.' '.$year->year.' is lower than '.$previous->year.'.',
                    );
                }

                $publishedYear = $publishedYears[$year->year] ?? null;

                if ($publishedYear instanceof RateYear) {
                    $publishedValue = $publishedYear->price($field);

                    if ($publishedValue > 0 && abs($value - $publishedValue) / $publishedValue > 0.15) {
                        $move = (($value - $publishedValue) / $publishedValue) * 100;
                        $warnings[] = new Warning(
                            $path,
                            $label.' '.$year->year.' moves '.number_format($move, 1).'% vs published — double-check.',
                        );
                    }
                }
            }

            if ($year->ownerPp <= $year->suitePp) {
                $warnings[] = new Warning(
                    'years.'.$index.'.owner_pp',
                    "Owner's Suite {$year->year} is not above the Suite rate.",
                );
            }
        }

        return [
            ...$warnings,
            ...app(StayConfigChecks::class)->forDocument($this),
            ...$this->supplementWarnings(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function publishErrors(?ConfigDocument $published): array
    {
        $errors = $this->unknownCancellationSets();

        if (! $published instanceof self) {
            return $errors;
        }

        if ($this->toArray()['years'] !== $published->toArray()['years']) {
            $errors['years'] = ['Legacy (yacht) year rates cannot be changed.'];
        }

        $rules = $this->rules->toArray();
        $publishedRules = $published->rules->toArray();

        foreach (self::legacyRuleFields() as $field) {
            if ($rules[$field] !== $publishedRules[$field]) {
                $errors['rules.'.$field] = ['Legacy (yacht) — this value cannot be changed.'];
            }
        }

        return $errors;
    }

    /**
     * @return array<string, list<string>>
     */
    private function unknownCancellationSets(): array
    {
        if ($this->ratePlans === []) {
            return [];
        }

        try {
            $codes = array_keys(app(CurrentConfig::class)->businessRules()->cancellationSets);
        } catch (RuntimeException) {
            $codes = [];
        }

        $errors = [];

        foreach ($this->ratePlans as $index => $plan) {
            if (! in_array($plan->cancellation, $codes, true)) {
                $errors['rate_plans.'.$index.'.cancellation'] = [
                    'Cancellation set '.$plan->cancellation.' does not exist.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @return list<Change>
     */
    public function changesAgainst(?ConfigDocument $published): array
    {
        if ($published !== null && ! $published instanceof self) {
            return parent::changesAgainst($published);
        }

        $from = $published?->toDiffArray() ?? [];
        $to = $this->toDiffArray();

        return DocumentDiff::compare($from, $to, self::diffLabels($from, $to));
    }

    public function year(int $year): ?RateYear
    {
        foreach ($this->years as $row) {
            if ($row->year === $year) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int, RateYear>
     */
    public function yearsByYear(): array
    {
        $byYear = [];

        foreach ($this->years as $year) {
            $byYear[$year->year] = $year;
        }

        return $byYear;
    }

    /**
     * @return array<string, mixed>
     */
    private static function source(): array
    {
        return [
            'currency' => 'USD',
            'years' => [
                ['year' => 2027, 'suite_pp' => 13300, 'owner_pp' => 25000, 'charter_week' => 199500],
                ['year' => 2028, 'suite_pp' => 13965, 'owner_pp' => 26250, 'charter_week' => 209475],
                ['year' => 2029, 'suite_pp' => 14663, 'owner_pp' => 27563, 'charter_week' => 219949],
            ],
            'terms' => [
                'cabin_deposit_pct' => 10,
                'cabin_balance_days' => 120,
                'charter_deposit_pct' => 20,
                'charter_deposit_business_days' => 5,
                'charter_balance_days' => 120,
            ],
            'rules' => [
                'single_supplement_pct' => 75,
                'triple_discount_pct' => 10,
                'child_discount_pct' => 15,
                'child_discounts_per_adult' => 1,
                'child_discounts_per_cabin' => 2,
                'back_to_back_pct' => 5,
                'festive_supplement_pp' => 750,
                'festive_supplement_charter' => 12000,
            ],
            ...RatesV2::keys(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function legacyRuleFields(): array
    {
        return [
            'single_supplement_pct',
            'triple_discount_pct',
            'child_discount_pct',
            'child_discounts_per_adult',
            'child_discounts_per_cabin',
            'back_to_back_pct',
            'festive_supplement_pp',
            'festive_supplement_charter',
        ];
    }

    /**
     * @return list<Season>
     */
    private static function seasonsFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $seasons = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $seasons[] = new Season(
                (string) ($row['code'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['from'] ?? ''),
                (string) ($row['to'] ?? ''),
            );
        }

        return $seasons;
    }

    /**
     * @return list<RoomRate>
     */
    private static function roomRatesFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $rates = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rates[] = new RoomRate(
                (string) ($row['room_type'] ?? ''),
                (string) ($row['season'] ?? ''),
                (int) ($row['nightly'] ?? 0),
            );
        }

        return $rates;
    }

    /**
     * @return list<LengthOfStayBand>
     */
    private static function lengthOfStayFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $bands = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $bands[] = new LengthOfStayBand(
                (int) ($row['min_nights'] ?? 0),
                (int) ($row['discount_pct'] ?? 0),
            );
        }

        return $bands;
    }

    /**
     * @return list<Supplement>
     */
    private static function supplementsFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $supplements = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $supplements[] = new Supplement(
                (string) ($row['code'] ?? ''),
                (string) ($row['label'] ?? ''),
                (string) ($row['from'] ?? ''),
                (string) ($row['to'] ?? ''),
                (int) ($row['per_night'] ?? 0),
                (string) ($row['basis'] ?? ''),
            );
        }

        return $supplements;
    }

    /**
     * @return list<RatePlan>
     */
    private static function ratePlansFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $plans = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $plans[] = new RatePlan(
                (string) ($row['code'] ?? ''),
                (string) ($row['name'] ?? ''),
                self::boolValue($row['default'] ?? false),
                (int) ($row['adjust_pct'] ?? 0),
                self::boolValue($row['refundable'] ?? false),
                (int) ($row['deposit_pct'] ?? 0),
                (int) ($row['balance_days'] ?? 0),
                (string) ($row['cancellation'] ?? ''),
                (string) ($row['meal_plan'] ?? ''),
            );
        }

        return $plans;
    }

    private static function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return list<Warning>
     */
    private function supplementWarnings(): array
    {
        $warnings = [];

        foreach ($this->supplements as $index => $supplement) {
            if ($supplement->from > $supplement->to) {
                continue;
            }

            $outside = false;

            for (
                $day = CarbonImmutable::parse($supplement->from);
                $day->toDateString() <= $supplement->to;
                $day = $day->addDay()
            ) {
                if ($this->seasonOn($day->toDateString()) === null) {
                    $outside = true;

                    break;
                }
            }

            if ($outside) {
                $warnings[] = new Warning(
                    'supplements.'.$index,
                    $supplement->label.' ('.$supplement->code.') includes nights outside every season.',
                );
            }
        }

        return $warnings;
    }

    private function seasonOn(string $date): ?Season
    {
        foreach ($this->seasons as $season) {
            if ($season->contains($date)) {
                return $season;
            }
        }

        return null;
    }

    /**
     * HTTP/storage shape stays a list. Diff keys seasons and plans by code
     * so one renamed season is one leaf.
     *
     * @return array<string, mixed>
     */
    private function toDiffArray(): array
    {
        $years = [];

        foreach ($this->years as $year) {
            $years[(string) $year->year] = [
                'suite_pp' => $year->suitePp,
                'owner_pp' => $year->ownerPp,
                'charter_week' => $year->charterWeek,
            ];
        }

        $seasons = [];

        foreach ($this->seasons as $season) {
            $seasons[$season->code] = [
                'name' => $season->name,
                'from' => $season->from,
                'to' => $season->to,
            ];
        }

        $roomRates = [];

        foreach ($this->roomRates as $rate) {
            $roomRates[$rate->roomType][$rate->season] = $rate->nightly;
        }

        $lengthOfStay = [];

        foreach ($this->lengthOfStay as $band) {
            $lengthOfStay[(string) $band->minNights] = $band->discountPct;
        }

        $supplements = [];

        foreach ($this->supplements as $supplement) {
            $supplements[$supplement->code] = [
                'label' => $supplement->label,
                'from' => $supplement->from,
                'to' => $supplement->to,
                'per_night' => $supplement->perNight,
                'basis' => $supplement->basis,
            ];
        }

        $ratePlans = [];

        foreach ($this->ratePlans as $plan) {
            $ratePlans[$plan->code] = [
                'name' => $plan->name,
                'default' => $plan->isDefault,
                'adjust_pct' => $plan->adjustPct,
                'refundable' => $plan->refundable,
                'deposit_pct' => $plan->depositPct,
                'balance_days' => $plan->balanceDays,
                'cancellation' => $plan->cancellation,
                'meal_plan' => $plan->mealPlan,
            ];
        }

        return [
            'currency' => $this->currency,
            'schema_version' => $this->schemaVersion,
            'years' => $years,
            'terms' => $this->terms->toArray(),
            'rules' => $this->rules->toArray(),
            'seasons' => $seasons,
            'room_rates' => $roomRates,
            'occupancy' => $this->occupancy->toArray(),
            'day_of_week' => $this->dayOfWeek->toArray(),
            'length_of_stay' => $lengthOfStay,
            'supplements' => $supplements,
            'rate_plans' => $ratePlans,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, string>
     */
    private static function diffLabels(array $from, array $to): array
    {
        $labels = self::labels();
        $fromYears = is_array($from['years'] ?? null) ? $from['years'] : [];
        $toYears = is_array($to['years'] ?? null) ? $to['years'] : [];

        foreach (array_unique([...array_keys($fromYears), ...array_keys($toYears)]) as $year) {
            $labels['years.'.$year.'.suite_pp'] = 'Legacy (yacht) Suite '.$year;
            $labels['years.'.$year.'.owner_pp'] = "Legacy (yacht) Owner's Suite ".$year;
            $labels['years.'.$year.'.charter_week'] = 'Legacy (yacht) Charter '.$year;
        }

        self::labelSeasons($labels, $from, $to);
        self::labelRoomRates($labels, $from, $to);
        self::labelLengthOfStay($labels, $from, $to);
        self::labelSupplements($labels, $from, $to);
        self::labelRatePlans($labels, $from, $to);

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function labelSeasons(array &$labels, array $from, array $to): void
    {
        $fromSeasons = is_array($from['seasons'] ?? null) ? $from['seasons'] : [];
        $toSeasons = is_array($to['seasons'] ?? null) ? $to['seasons'] : [];

        foreach (array_unique([...array_keys($fromSeasons), ...array_keys($toSeasons)]) as $code) {
            $row = is_array($toSeasons[$code] ?? null) ? $toSeasons[$code] : [];
            $previous = is_array($fromSeasons[$code] ?? null) ? $fromSeasons[$code] : [];
            $name = is_string($row['name'] ?? null) ? $row['name'] : (is_string($previous['name'] ?? null) ? $previous['name'] : (string) $code);
            $labels['seasons.'.$code.'.name'] = 'Season '.$name;
            $labels['seasons.'.$code.'.from'] = 'Season '.$name.' from';
            $labels['seasons.'.$code.'.to'] = 'Season '.$name.' to';
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function labelRoomRates(array &$labels, array $from, array $to): void
    {
        $fromRates = is_array($from['room_rates'] ?? null) ? $from['room_rates'] : [];
        $toRates = is_array($to['room_rates'] ?? null) ? $to['room_rates'] : [];

        foreach (array_unique([...array_keys($fromRates), ...array_keys($toRates)]) as $type) {
            $fromSeasons = is_array($fromRates[$type] ?? null) ? $fromRates[$type] : [];
            $toSeasons = is_array($toRates[$type] ?? null) ? $toRates[$type] : [];

            foreach (array_unique([...array_keys($fromSeasons), ...array_keys($toSeasons)]) as $season) {
                $labels['room_rates.'.$type.'.'.$season] = $type.' · '.$season.' nightly';
            }
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function labelLengthOfStay(array &$labels, array $from, array $to): void
    {
        $fromBands = is_array($from['length_of_stay'] ?? null) ? $from['length_of_stay'] : [];
        $toBands = is_array($to['length_of_stay'] ?? null) ? $to['length_of_stay'] : [];

        foreach (array_unique([...array_keys($fromBands), ...array_keys($toBands)]) as $nights) {
            $labels['length_of_stay.'.$nights] = $nights.' nights or more — discount %';
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function labelSupplements(array &$labels, array $from, array $to): void
    {
        $fromRows = is_array($from['supplements'] ?? null) ? $from['supplements'] : [];
        $toRows = is_array($to['supplements'] ?? null) ? $to['supplements'] : [];

        foreach (array_unique([...array_keys($fromRows), ...array_keys($toRows)]) as $code) {
            $row = is_array($toRows[$code] ?? null) ? $toRows[$code] : [];
            $previous = is_array($fromRows[$code] ?? null) ? $fromRows[$code] : [];
            $label = is_string($row['label'] ?? null) ? $row['label'] : (is_string($previous['label'] ?? null) ? $previous['label'] : (string) $code);
            $prefix = $label.' ('.$code.')';
            $labels['supplements.'.$code.'.label'] = $prefix;
            $labels['supplements.'.$code.'.from'] = $prefix.' from';
            $labels['supplements.'.$code.'.to'] = $prefix.' to';
            $labels['supplements.'.$code.'.per_night'] = $prefix.' per night';
            $labels['supplements.'.$code.'.basis'] = $prefix.' basis';
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private static function labelRatePlans(array &$labels, array $from, array $to): void
    {
        $fromPlans = is_array($from['rate_plans'] ?? null) ? $from['rate_plans'] : [];
        $toPlans = is_array($to['rate_plans'] ?? null) ? $to['rate_plans'] : [];

        foreach (array_unique([...array_keys($fromPlans), ...array_keys($toPlans)]) as $code) {
            $row = is_array($toPlans[$code] ?? null) ? $toPlans[$code] : [];
            $previous = is_array($fromPlans[$code] ?? null) ? $fromPlans[$code] : [];
            $name = is_string($row['name'] ?? null) ? $row['name'] : (is_string($previous['name'] ?? null) ? $previous['name'] : (string) $code);
            $prefix = $name.' ('.$code.')';
            $labels['rate_plans.'.$code.'.name'] = $prefix;
            $labels['rate_plans.'.$code.'.default'] = $prefix.' default';
            $labels['rate_plans.'.$code.'.adjust_pct'] = $prefix.' adjustment %';
            $labels['rate_plans.'.$code.'.refundable'] = $prefix.' refundable';
            $labels['rate_plans.'.$code.'.deposit_pct'] = $prefix.' deposit %';
            $labels['rate_plans.'.$code.'.balance_days'] = $prefix.' balance days';
            $labels['rate_plans.'.$code.'.cancellation'] = $prefix.' cancellation';
            $labels['rate_plans.'.$code.'.meal_plan'] = $prefix.' meal plan';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function priceFields(): array
    {
        return [
            'SUITE' => 'Suite',
            'OWNER' => "Owner's Suite",
            'CHARTER' => 'Charter',
        ];
    }

    private static function fieldKey(string $category): string
    {
        return match ($category) {
            'SUITE' => 'suite_pp',
            'OWNER' => 'owner_pp',
            default => 'charter_week',
        };
    }

    private static function yearsAscending(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $years = [];

            foreach ($value as $row) {
                if (! is_array($row) || ! array_key_exists('year', $row) || ! is_numeric($row['year'])) {
                    return;
                }

                $years[] = (int) $row['year'];
            }

            $sorted = $years;
            sort($sorted);

            if ($years !== $sorted) {
                $fail('The years must be sorted in ascending order.');
            }
        };
    }

    private static function seasonBounds(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $row) {
                if (! is_array($row) || ! self::isIsoDate($row['from'] ?? null) || ! self::isIsoDate($row['to'] ?? null)) {
                    continue;
                }

                if ($row['from'] > $row['to']) {
                    $code = is_string($row['code'] ?? null) && $row['code'] !== '' ? $row['code'] : 'season';
                    $fail('Season '.$code.' ends before it starts.');
                }
            }
        };
    }

    private static function supplementBounds(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $row) {
                if (! is_array($row) || ! self::isIsoDate($row['from'] ?? null) || ! self::isIsoDate($row['to'] ?? null)) {
                    continue;
                }

                if ($row['from'] > $row['to']) {
                    $code = is_string($row['code'] ?? null) && $row['code'] !== '' ? $row['code'] : 'supplement';
                    $fail('Supplement '.$code.' ends before it starts.');
                }
            }
        };
    }

    private static function lengthOfStayAscending(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $nights = [];

            foreach ($value as $row) {
                if (! is_array($row) || ! is_numeric($row['min_nights'] ?? null)) {
                    return;
                }

                $nights[] = (int) $row['min_nights'];
            }

            $sorted = $nights;
            sort($sorted);

            if ($nights !== $sorted) {
                $fail('Length-of-stay bands must be in ascending order of minimum nights.');
            }
        };
    }

    private static function isIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
