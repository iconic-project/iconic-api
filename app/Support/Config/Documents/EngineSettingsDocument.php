<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\ConfigKind;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Change;
use App\Support\Config\ConfigDocument;
use App\Support\Config\Warning;
use Illuminate\Validation\Rule;

final class EngineSettingsDocument extends ConfigDocument
{
    public function __construct(
        public readonly GuestsSettings $guests,
        public readonly CalendarSettings $calendar,
        public readonly LocaleSettings $locale,
        public readonly FeesSettings $fees,
        public readonly CopySettings $copy,
        public readonly AvailabilitySettings $availability,
        /** @var array<string, mixed>|null Retained so the charter-key migration can diff the group out. */
        public readonly ?array $charter = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function copyPaths(): array
    {
        return [
            'fees.footnote',
            'copy.book_now_pay_later',
            'copy.traveling_with_children',
            'copy.solo_and_triple',
            'copy.pay_today',
            'copy.details_note',
            'copy.confirmation_steps',
            'copy.online_deposit_advantage',
            'copy.online_deposit_perk',
        ];
    }

    public static function isCopyPath(string $path): bool
    {
        if (str_starts_with($path, 'copy.')) {
            return true;
        }

        return in_array($path, self::copyPaths(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function initial(): array
    {
        return [
            'guests' => [
                'max_per_property' => 16,
                'child_min_age' => 6,
                'child_max_age' => 17,
                'adult_required_with_children' => true,
                'under_age_message' => 'Under 6 not accommodated',
            ],
            'calendar' => [
                'default_search_from' => '2027-11',
                'default_search_to' => '2028-01',
                'default_adults' => 2,
                'horizon_months' => 24,
            ],
            'locale' => [
                'default' => 'en',
                'live' => ['en'],
                'currency' => 'USD',
            ],
            'fees' => [
                'show_in_price_panel' => true,
                'footnote' => 'Informational — local fees are not charged today.',
            ],
            'copy' => [
                'book_now_pay_later' => 'We will hold the rooms for you, obligation-free. Our team confirms availability and sends your deposit link — nothing is charged today.',
                'traveling_with_children' => 'Children aged 6–17 are welcome. One adult must travel with them.',
                'solo_and_triple' => 'Single occupancy and extra guests are priced per night. Shown live in the next step.',
                'pay_today' => 'We will hold the rooms for you, obligation-free. Our team confirms availability and sends your deposit link (10%) — nothing is charged until you decide.',
                'details_note' => 'Full guest details (passports, dietary preferences) and payment are arranged after we confirm your rooms — no card is required today. Travel insurance is the sole responsibility of the passenger. Iconic does not sell or intermediate travel insurance.',
                'confirmation_steps' => [
                    'Within 24 hours a member of our team confirms your rooms and answers any questions — by your preferred channel.',
                    'You receive your booking confirmation and deposit link (10%). Your rooms stay held while you decide, per our hold policy.',
                    'After the deposit, we gather guest details and preferences, and our concierge curates flights, stays and in-house touches.',
                ],
                'online_deposit_advantage' => 'Online deposit advantage',
                'online_deposit_perk' => 'Complimentary spa access aboard',
            ],
            'availability' => [
                'low_availability_threshold' => 3,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $guests = is_array($data['guests'] ?? null) ? $data['guests'] : [];
        $calendar = is_array($data['calendar'] ?? null) ? $data['calendar'] : [];
        $locale = is_array($data['locale'] ?? null) ? $data['locale'] : [];
        $fees = is_array($data['fees'] ?? null) ? $data['fees'] : [];
        $copy = is_array($data['copy'] ?? null) ? $data['copy'] : [];
        $charter = is_array($data['charter'] ?? null) ? $data['charter'] : null;
        $availability = is_array($data['availability'] ?? null) ? $data['availability'] : [];

        $live = [];
        foreach ($locale['live'] ?? [] as $item) {
            if (is_string($item)) {
                $live[] = $item;
            }
        }

        $steps = [];
        foreach ($copy['confirmation_steps'] ?? [] as $step) {
            if (is_string($step)) {
                $steps[] = $step;
            }
        }

        return new self(
            new GuestsSettings(
                (int) ($guests['max_per_property'] ?? 0),
                (int) ($guests['child_min_age'] ?? 0),
                (int) ($guests['child_max_age'] ?? 0),
                (bool) ($guests['adult_required_with_children'] ?? false),
                (string) ($guests['under_age_message'] ?? ''),
            ),
            new CalendarSettings(
                (string) ($calendar['default_search_from'] ?? ''),
                (string) ($calendar['default_search_to'] ?? ''),
                (int) ($calendar['default_adults'] ?? 0),
                (int) ($calendar['horizon_months'] ?? 0),
            ),
            new LocaleSettings(
                (string) ($locale['default'] ?? ''),
                $live,
                (string) ($locale['currency'] ?? ''),
            ),
            new FeesSettings(
                (bool) ($fees['show_in_price_panel'] ?? false),
                (string) ($fees['footnote'] ?? ''),
            ),
            new CopySettings(
                (string) ($copy['book_now_pay_later'] ?? ''),
                (string) ($copy['traveling_with_children'] ?? ''),
                (string) ($copy['solo_and_triple'] ?? ''),
                (string) ($copy['pay_today'] ?? ''),
                (string) ($copy['details_note'] ?? ''),
                $steps,
                (string) ($copy['online_deposit_advantage'] ?? ''),
                (string) ($copy['online_deposit_perk'] ?? ''),
            ),
            new AvailabilitySettings(
                array_key_exists('low_availability_threshold', $availability)
                    ? (int) $availability['low_availability_threshold']
                    : 3,
            ),
            $charter,
        );
    }

    /**
     * @return array{
     *     guests: array{
     *         max_per_property: int,
     *         child_min_age: int,
     *         child_max_age: int,
     *         adult_required_with_children: bool,
     *         under_age_message: string
     *     },
     *     calendar: array{
     *         default_search_from: string,
     *         default_search_to: string,
     *         default_adults: int,
     *         horizon_months: int
     *     },
     *     locale: array{default: string, live: list<string>, currency: string},
     *     fees: array{
     *         show_in_price_panel: bool,
     *         footnote: string
     *     },
     *     copy: array{
     *         book_now_pay_later: string,
     *         traveling_with_children: string,
     *         solo_and_triple: string,
     *         pay_today: string,
     *         details_note: string,
     *         confirmation_steps: list<string>,
     *         online_deposit_advantage: string,
     *         online_deposit_perk: string
     *     },
     *     availability: array{low_availability_threshold: int},
     *     charter?: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $document = [
            'guests' => $this->guests->toArray(),
            'calendar' => $this->calendar->toArray(),
            'locale' => $this->locale->toArray(),
            'fees' => $this->fees->toArray(),
            'copy' => $this->copy->toArray(),
            'availability' => $this->availability->toArray(),
        ];

        if ($this->charter !== null) {
            $document['charter'] = $this->charter;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $month = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

        return [
            'guests' => ['required', 'array'],
            'guests.max_per_property' => ['required', 'integer', 'min:1', 'max:36'],
            'guests.child_min_age' => ['required', 'integer', 'min:0', 'max:17'],
            'guests.child_max_age' => ['required', 'integer', 'min:0', 'max:17', new EngineSettingsConstraint('child_ages_ordered')],
            'guests.adult_required_with_children' => ['required', 'boolean'],
            'guests.under_age_message' => ['required', 'string', 'min:1', 'max:60'],
            'calendar' => ['required', 'array'],
            'calendar.default_search_from' => $month,
            'calendar.default_search_to' => [...$month, new EngineSettingsConstraint('search_range_ordered')],
            'calendar.default_adults' => ['required', 'integer', 'min:1', 'max:16', new EngineSettingsConstraint('default_adults_capacity')],
            'calendar.horizon_months' => ['required', 'integer', 'min:6', 'max:36'],
            'locale' => ['required', 'array'],
            'locale.default' => ['required', 'string', Rule::in(['en'])],
            'locale.live' => ['required', 'array', 'size:1'],
            'locale.live.0' => ['required', 'string', Rule::in(['en'])],
            'locale.currency' => ['required', 'string', Rule::in(['USD'])],
            'fees' => ['required', 'array'],
            'fees.show_in_price_panel' => ['required', 'boolean'],
            'fees.footnote' => ['required', 'string', 'min:1', 'max:320'],
            'copy' => ['required', 'array'],
            'copy.book_now_pay_later' => ['required', 'string', 'min:1', 'max:320'],
            'copy.traveling_with_children' => ['required', 'string', 'min:1', 'max:320'],
            'copy.solo_and_triple' => ['required', 'string', 'min:1', 'max:320'],
            'copy.pay_today' => ['required', 'string', 'min:1', 'max:320'],
            'copy.details_note' => ['required', 'string', 'min:1', 'max:320'],
            'copy.confirmation_steps' => ['required', 'array', 'size:3'],
            'copy.online_deposit_advantage' => ['required', 'string', 'min:1', 'max:80'],
            'copy.online_deposit_perk' => ['required', 'string', 'min:1', 'max:120'],
            'copy.confirmation_steps.*' => ['required', 'string', 'min:1', 'max:320'],
            'availability' => ['required', 'array'],
            'availability.low_availability_threshold' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'guests.max_per_property' => 'Max guests per property',
            'guests.child_min_age' => 'Child minimum age',
            'guests.child_max_age' => 'Child maximum age',
            'guests.adult_required_with_children' => 'Adult required with children',
            'guests.under_age_message' => 'Under-age message',
            'calendar.default_search_from' => 'Default search — from',
            'calendar.default_search_to' => 'Default search — to',
            'calendar.default_adults' => 'Default adults',
            'calendar.horizon_months' => 'Booking horizon',
            'locale.default' => 'Locale',
            'locale.live' => 'Live locales',
            'locale.currency' => 'Currency',
            'fees.show_in_price_panel' => 'Show fees in price panel',
            'fees.footnote' => 'Fee footnote',
            'copy.book_now_pay_later' => 'Note — Book now, pay later',
            'copy.traveling_with_children' => 'Note — Traveling with children',
            'copy.solo_and_triple' => 'Note — Solo & triple',
            'copy.pay_today' => 'Pay-today box',
            'copy.details_note' => 'Details-page note',
            'copy.confirmation_steps' => 'Confirmation steps',
            'copy.online_deposit_advantage' => 'Online-deposit advantage label',
            'copy.online_deposit_perk' => 'Online-deposit perk',
            'availability.low_availability_threshold' => 'Low availability threshold',
        ];
    }

    public static function kind(): ConfigKind
    {
        return ConfigKind::EngineSettings;
    }

    /**
     * @param  list<Change>  $changes
     */
    public function requiresApprovalReference(array $changes): bool
    {
        foreach ($changes as $change) {
            if (! self::isCopyPath($change->path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function publishErrors(?ConfigDocument $published): array
    {
        return [];
    }

    /**
     * @return list<Warning>
     */
    public function warnings(?ConfigDocument $published): array
    {
        $warnings = [];

        if ($this->guests->maxPerProperty !== 16) {
            $warnings[] = new Warning(
                'guests.max_per_property',
                'The property guest cap will show '.$this->guests->maxPerProperty.' guests (follows max per property).',
            );
        }

        $ages = $this->guests->childMinAge.'–'.$this->guests->childMaxAge;
        if (preg_match('/\d+\s*[–-]\s*\d+/u', $this->copy->travelingWithChildren) === 1
            && ! str_contains($this->copy->travelingWithChildren, $ages)
        ) {
            $warnings[] = new Warning(
                'copy.traveling_with_children',
                '"Traveling with children" mentions different ages than the guest rules ('.$ages.').',
            );
        }

        $underAge = self::firstInt($this->guests->underAgeMessage, '/(\d+)/');
        if ($underAge !== null && $underAge !== $this->guests->childMinAge) {
            $warnings[] = new Warning(
                'guests.under_age_message',
                'Under-age message says '.$underAge.' but children are accepted from age '.$this->guests->childMinAge.'.',
            );
        }

        $rates = self::publishedRates();

        if ($rates instanceof RatesDocument) {
            $depositPct = self::firstInt($this->copy->payToday, '/\((\d+)%\)/');
            $planDeposit = null;

            foreach ($rates->ratePlans as $plan) {
                if ($plan->isDefault) {
                    $planDeposit = $plan->depositPct;

                    break;
                }
            }

            if ($depositPct !== null && $planDeposit !== null && $depositPct !== $planDeposit) {
                $warnings[] = new Warning(
                    'copy.pay_today',
                    '"Pay today" box says '.$depositPct.'% deposit — the default rate plan deposit is '.$planDeposit.'%.',
                );
            }
        }

        $rules = self::publishedBusinessRules();

        if ($rules instanceof BusinessRulesDocument) {
            $step = $this->copy->confirmationSteps[0] ?? '';
            $stepHours = self::firstInt($step, '/(\d+) hours/i');

            if ($stepHours !== null && $stepHours !== $rules->sla->responseHours) {
                $warnings[] = new Warning(
                    'copy.confirmation_steps',
                    'Confirmation step 1 says '.$stepHours.' h but the response SLA is '.$rules->sla->responseHours.' h.',
                );
            }
        }

        return $warnings;
    }

    private static function publishedRates(): ?RatesDocument
    {
        $current = app(CurrentConfig::class);

        if (! $current->has(ConfigKind::Rates)) {
            return null;
        }

        return $current->rates();
    }

    private static function publishedBusinessRules(): ?BusinessRulesDocument
    {
        $current = app(CurrentConfig::class);

        if (! $current->has(ConfigKind::BusinessRules)) {
            return null;
        }

        return $current->businessRules();
    }

    private static function firstInt(string $text, string $pattern): ?int
    {
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        if (! isset($matches[1]) || ! is_numeric($matches[1])) {
            return null;
        }

        return (int) $matches[1];
    }
}
