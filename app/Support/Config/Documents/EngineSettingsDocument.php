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
        public readonly CharterSettings $charter,
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
            'charter.headline',
            'charter.intro',
            'charter.itinerary_label',
            'charter.group_contexts',
            'charter.thank_you',
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
                'max_per_cabin' => 3,
                'max_per_yacht' => 16,
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
                'tct_pp' => 20,
                'png' => [
                    'foreign_over_12' => 200,
                    'foreign_12_and_under' => 100,
                    'can_adult' => 100,
                    'can_minor' => 30,
                    'national_or_resident' => 30,
                    'exempt_under_age' => 2,
                ],
                'show_in_price_panel' => true,
                'footnote' => 'Informational — regulatory Galápagos fees, not charged today. PNG is paid at SCY airport; TCT is arranged with our team.',
            ],
            'copy' => [
                'book_now_pay_later' => 'We will hold the cabins for you, obligation-free. Our team confirms availability and sends your deposit link — nothing is charged today.',
                'traveling_with_children' => 'A 15% discount applies to children aged 6–17. One discount per adult, maximum two per couple. Not available on festive departures.',
                'solo_and_triple' => 'Single occupancy +75% ppdo · triple sharing −10% ppdo. Shown live in the next step.',
                'pay_today' => 'We will hold your cabins for you, obligation-free. Our team confirms availability and sends your deposit link (10%) — nothing is charged until you decide.',
                'details_note' => 'Full guest details (passports, dietary preferences) and payment are arranged after we confirm your cabins — no card is required today. Travel insurance is the sole responsibility of the passenger. Iconic does not sell or intermediate travel insurance.',
                'confirmation_steps' => [
                    'Within 24 hours a member of our team confirms your cabins and answers any questions — by your preferred channel.',
                    'You receive your booking confirmation and deposit link (10%). Your cabins stay held while you decide, per our hold policy.',
                    'After the deposit, we gather guest details and preferences, and our concierge curates flights, stays and on-board touches.',
                ],
                'online_deposit_advantage' => 'Online deposit advantage',
                'online_deposit_perk' => 'Complimentary spa access aboard',
            ],
            'charter' => [
                'headline' => 'The yacht, entirely yours',
                'intro' => 'One yacht, sixteen guests of your choosing, and an itinerary shaped around your group within the protected waters of the Galápagos. From USD 199,500 per week. Our team responds to every charter enquiry within 24 hours.',
                'itinerary_label' => 'Customizable',
                'response_sla_hours' => 24,
                'group_contexts' => [
                    'Family',
                    'Friends',
                    'Corporate / Incentive',
                    'Celebration',
                ],
                'thank_you' => 'Thank you — your charter enquiry has been received. A dedicated member of our team will contact you within 24 hours to schedule a discovery call.',
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
        $png = is_array($fees['png'] ?? null) ? $fees['png'] : [];
        $copy = is_array($data['copy'] ?? null) ? $data['copy'] : [];
        $charter = is_array($data['charter'] ?? null) ? $data['charter'] : [];

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

        $contexts = [];
        foreach ($charter['group_contexts'] ?? [] as $context) {
            if (is_string($context)) {
                $contexts[] = $context;
            }
        }

        return new self(
            new GuestsSettings(
                (int) ($guests['max_per_cabin'] ?? 0),
                (int) ($guests['max_per_yacht'] ?? 0),
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
                (int) ($fees['tct_pp'] ?? 0),
                new PngFees(
                    (int) ($png['foreign_over_12'] ?? 0),
                    (int) ($png['foreign_12_and_under'] ?? 0),
                    (int) ($png['can_adult'] ?? 0),
                    (int) ($png['can_minor'] ?? 0),
                    (int) ($png['national_or_resident'] ?? 0),
                    (int) ($png['exempt_under_age'] ?? 0),
                ),
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
            new CharterSettings(
                (string) ($charter['headline'] ?? ''),
                (string) ($charter['intro'] ?? ''),
                (string) ($charter['itinerary_label'] ?? ''),
                (int) ($charter['response_sla_hours'] ?? 0),
                $contexts,
                (string) ($charter['thank_you'] ?? ''),
            ),
        );
    }

    /**
     * @return array{
     *     guests: array{
     *         max_per_cabin: int,
     *         max_per_yacht: int,
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
     *         tct_pp: int,
     *         png: array{
     *             foreign_over_12: int,
     *             foreign_12_and_under: int,
     *             can_adult: int,
     *             can_minor: int,
     *             national_or_resident: int,
     *             exempt_under_age: int
     *         },
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
     *     charter: array{
     *         headline: string,
     *         intro: string,
     *         itinerary_label: string,
     *         response_sla_hours: int,
     *         group_contexts: list<string>,
     *         thank_you: string
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'guests' => $this->guests->toArray(),
            'calendar' => $this->calendar->toArray(),
            'locale' => $this->locale->toArray(),
            'fees' => $this->fees->toArray(),
            'copy' => $this->copy->toArray(),
            'charter' => $this->charter->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $month = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

        return [
            'guests' => ['required', 'array'],
            'guests.max_per_cabin' => ['required', 'integer', 'min:1', 'max:4'],
            'guests.max_per_yacht' => ['required', 'integer', 'min:1', 'max:36', new EngineSettingsConstraint('yacht_fits_cabins')],
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
            'fees.tct_pp' => ['required', 'integer', 'min:0'],
            'fees.png' => ['required', 'array'],
            'fees.png.foreign_over_12' => ['required', 'integer', 'min:0'],
            'fees.png.foreign_12_and_under' => ['required', 'integer', 'min:0'],
            'fees.png.can_adult' => ['required', 'integer', 'min:0'],
            'fees.png.can_minor' => ['required', 'integer', 'min:0'],
            'fees.png.national_or_resident' => ['required', 'integer', 'min:0'],
            'fees.png.exempt_under_age' => ['required', 'integer', 'min:0', 'max:12'],
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
            'charter' => ['required', 'array'],
            'charter.headline' => ['required', 'string', 'min:1', 'max:60'],
            'charter.intro' => ['required', 'string', 'min:1', 'max:320'],
            'charter.itinerary_label' => ['required', 'string', 'min:1', 'max:30'],
            'charter.response_sla_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'charter.group_contexts' => ['required', 'array', 'min:1', 'max:8'],
            'charter.group_contexts.*' => ['required', 'string', 'min:1', 'distinct'],
            'charter.thank_you' => ['required', 'string', 'min:1', 'max:320'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'guests.max_per_cabin' => 'Max guests per cabin',
            'guests.max_per_yacht' => 'Max guests per yacht',
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
            'fees.tct_pp' => 'TCT transit card',
            'fees.png.foreign_over_12' => 'PNG fee — foreign visitor over 12',
            'fees.png.foreign_12_and_under' => 'PNG fee — foreign visitor 12 and under',
            'fees.png.can_adult' => 'PNG fee — CAN adult',
            'fees.png.can_minor' => 'PNG fee — CAN minor',
            'fees.png.national_or_resident' => 'PNG fee — national or resident',
            'fees.png.exempt_under_age' => 'PNG fee — exempt under age',
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
            'charter.headline' => 'Charter headline',
            'charter.intro' => 'Charter intro',
            'charter.itinerary_label' => 'Charter itinerary label',
            'charter.response_sla_hours' => 'Charter response SLA',
            'charter.group_contexts' => 'Charter group contexts',
            'charter.thank_you' => 'Charter thank-you message',
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
     * @return list<Warning>
     */
    public function warnings(?ConfigDocument $published): array
    {
        $warnings = [];

        if ($this->guests->maxPerYacht !== 16) {
            $warnings[] = new Warning(
                'guests.max_per_yacht',
                'Charter capacity will show '.$this->guests->maxPerYacht.' guests (follows max per yacht).',
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

        $introSla = self::firstInt($this->charter->intro, '/within (\d+) hours/i');
        if ($introSla !== null && $introSla !== $this->charter->responseSlaHours) {
            $warnings[] = new Warning(
                'charter.intro',
                'Charter intro says "within '.$introSla.' hours" but the SLA is '.$this->charter->responseSlaHours.' h.',
            );
        }

        $thanksSla = self::firstInt($this->charter->thankYou, '/within (\d+) hours/i');
        if ($thanksSla !== null && $thanksSla !== $this->charter->responseSlaHours) {
            $warnings[] = new Warning(
                'charter.thank_you',
                'Charter thank-you says "within '.$thanksSla.' hours" but the SLA is '.$this->charter->responseSlaHours.' h.',
            );
        }

        $rates = self::publishedRates();

        if ($rates instanceof RatesDocument) {
            $childPct = self::firstInt($this->copy->travelingWithChildren, '/(\d+)%/');
            if ($childPct !== null && $childPct !== $rates->rules->childDiscountPct) {
                $warnings[] = new Warning(
                    'copy.traveling_with_children',
                    '"Traveling with children" says '.$childPct.'% — the child discount is '.$rates->rules->childDiscountPct.'%.',
                );
            }

            $singlePct = self::firstInt($this->copy->soloAndTriple, '/\+(\d+)%/');
            if ($singlePct !== null && $singlePct !== $rates->rules->singleSupplementPct) {
                $warnings[] = new Warning(
                    'copy.solo_and_triple',
                    '"Solo & triple" says +'.$singlePct.'% — the single supplement is '.$rates->rules->singleSupplementPct.'%.',
                );
            }

            $triplePct = self::firstInt($this->copy->soloAndTriple, '/[−-](\d+)%/u');
            if ($triplePct !== null && $triplePct !== $rates->rules->tripleDiscountPct) {
                $warnings[] = new Warning(
                    'copy.solo_and_triple',
                    '"Solo & triple" says −'.$triplePct.'% — the triple discount is '.$rates->rules->tripleDiscountPct.'%.',
                );
            }

            $depositPct = self::firstInt($this->copy->payToday, '/\((\d+)%\)/');
            if ($depositPct !== null && $depositPct !== $rates->terms->cabinDepositPct) {
                $warnings[] = new Warning(
                    'copy.pay_today',
                    '"Pay today" box says '.$depositPct.'% deposit — the cabin deposit is '.$rates->terms->cabinDepositPct.'%.',
                );
            }

            $quoted = self::quotedUsd($this->charter->intro);
            $firstYear = $rates->years[0] ?? null;
            if ($quoted !== null && $firstYear instanceof RateYear && $quoted !== $firstYear->charterWeek) {
                $warnings[] = new Warning(
                    'charter.intro',
                    'Charter intro quotes USD '.number_format($quoted).'; the '.$firstYear->year.' charter rate is USD '.number_format($firstYear->charterWeek).'.',
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

    private static function quotedUsd(string $text): ?int
    {
        if (preg_match('/USD\s?([\d,]+)/', $text, $matches) !== 1) {
            return null;
        }

        $digits = str_replace(',', '', $matches[1]);

        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        return (int) $digits;
    }
}
