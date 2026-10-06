<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Validator;

test('rules reject ranges, default above cap, reminder order and band shape', function (): void {
    $invalid = businessRulesDocument([
        'commission' => [
            'cap_pct' => 31,
            'default_pct' => 40,
            'payable_days_after_check_out' => 121,
        ],
        'modification_fee_usd' => 10001,
        'payments' => [
            'extras_due_hours' => 2161,
            'wire_window_hours' => 11,
            'balance_reminder_days' => [7, 21],
        ],
        'discounts' => [
            'online_deposit_discount_pct' => 101,
            'max_total_discount_pct' => 101,
        ],
        'holds' => [
            'web_minutes' => 4,
            'web_extension_minutes' => 61,
            'near_term_business_hours' => 3,
            'long_lead_business_days' => 16,
        ],
        'sla' => [
            'response_hours' => 0,
            'refund_business_days' => 61,
            'agency_approval_business_days' => 11,
        ],
        'alerts' => [
            'low_occupancy_pct' => 0,
            'low_occupancy_days_before' => 366,
            'low_occupancy_min_consecutive_nights' => 0,
        ],
        'retention' => [
            'passport_months_after_check_out' => 0,
            'medical_days_after_check_out' => 3651,
            'behavioural_raw_months' => 0,
            'behavioural_unstitched_days' => 3651,
        ],
    ]);
    $invalid['cancellation']['bands'] = [];

    $errors = Validator::make($invalid, BusinessRulesDocument::rules())->errors();

    expect($errors->has('commission.cap_pct'))->toBeTrue();
    expect($errors->has('commission.default_pct'))->toBeTrue();
    expect($errors->has('commission.payable_days_after_check_out'))->toBeTrue();
    expect($errors->has('modification_fee_usd'))->toBeTrue();
    expect($errors->has('payments.extras_due_hours'))->toBeTrue();
    expect($errors->has('payments.wire_window_hours'))->toBeTrue();
    expect($errors->has('payments.balance_reminder_days'))->toBeTrue();
    expect($errors->has('discounts.online_deposit_discount_pct'))->toBeTrue();
    expect($errors->has('discounts.max_total_discount_pct'))->toBeTrue();
    expect($errors->has('holds.web_minutes'))->toBeTrue();
    expect($errors->has('holds.web_extension_minutes'))->toBeTrue();
    expect($errors->has('holds.near_term_business_hours'))->toBeTrue();
    expect($errors->has('holds.long_lead_business_days'))->toBeTrue();
    expect($errors->has('sla.response_hours'))->toBeTrue();
    expect($errors->has('sla.refund_business_days'))->toBeTrue();
    expect($errors->has('sla.agency_approval_business_days'))->toBeTrue();
    expect($errors->has('alerts.low_occupancy_pct'))->toBeTrue();
    expect($errors->has('alerts.low_occupancy_days_before'))->toBeTrue();
    expect($errors->has('alerts.low_occupancy_min_consecutive_nights'))->toBeTrue();
    expect($errors->has('retention.passport_months_after_check_out'))->toBeTrue();
    expect($errors->has('retention.medical_days_after_check_out'))->toBeTrue();
    expect($errors->has('retention.behavioural_raw_months'))->toBeTrue();
    expect($errors->has('retention.behavioural_unstitched_days'))->toBeTrue();
    expect($errors->has('cancellation.bands'))->toBeTrue();
});

test('rules reject empty consent version labels', function (): void {
    $document = businessRulesDocument([
        'legal' => [
            'consent_versions' => [
                'terms' => '',
                'marketing' => '',
            ],
        ],
    ]);

    $errors = Validator::make($document, BusinessRulesDocument::rules())->errors();

    expect($errors->has('legal.consent_versions.terms'))->toBeTrue();
    expect($errors->has('legal.consent_versions.marketing'))->toBeTrue();
});

test('fromArray fills missing consent versions as empty strings', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['legal']);

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->consentVersions->terms)->toBe('');
    expect($lenient->consentVersions->marketing)->toBe('');
    expect($lenient->consentVersions->analytics)->toBe('');
});

test('fromArray fills missing legal_entity bank fields as TBD', function (): void {
    $missing = BusinessRulesDocument::initial();
    unset($missing['legal_entity']);

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->legalEntity->name)->toBe('');
    expect($lenient->legalEntity->addressLines)->toBe([]);
    expect($lenient->legalEntity->bank->bankName)->toBe('[TBD]');
    expect($lenient->legalEntity->bank->swift)->toBe('[TBD]');
});

test('rules reject duplicate band days and a missing zero band', function (): void {
    $document = businessRulesDocument();
    $document['cancellation']['bands'] = [
        ['min_days' => 120, 'penalty_pct' => 5],
        ['min_days' => 120, 'penalty_pct' => 10],
    ];

    $errors = Validator::make($document, BusinessRulesDocument::rules())->errors();

    expect($errors->has('cancellation.bands'))->toBeTrue();

    $document['cancellation']['bands'] = [
        ['min_days' => 120, 'penalty_pct' => 5],
        ['min_days' => 90, 'penalty_pct' => 50],
    ];

    $errors = Validator::make($document, BusinessRulesDocument::rules())->errors();

    expect($errors->first('cancellation.bands'))->toBe('The last cancellation band must start at 0 days.');
});

test('rules reject empty business days, a day end that is not after start, and a bad holiday', function (): void {
    $document = businessRulesDocument([
        'holds' => [
            'business_day_start' => '18:00',
            'business_day_end' => '09:00',
            'holidays' => ['not-a-date'],
            'near_term_max_days' => 0,
        ],
    ]);
    $document['holds']['business_days'] = [];

    $errors = Validator::make($document, BusinessRulesDocument::rules())->errors();

    expect($errors->has('holds.business_days'))->toBeTrue();
    expect($errors->has('holds.business_day_end'))->toBeTrue();
    expect($errors->has('holds.holidays.0'))->toBeTrue();
    expect($errors->has('holds.near_term_max_days'))->toBeTrue();
});

test('fromArray sorts holidays and business days and fills missing hold fields', function (): void {
    $document = BusinessRulesDocument::fromArray(businessRulesDocument([
        'holds' => [
            'business_days' => [5, 1, 1, 3],
            'holidays' => ['2026-12-25', '2026-01-01'],
        ],
    ]));

    expect($document->holds->businessDays)->toBe([1, 3, 5]);
    expect($document->holds->holidays)->toBe(['2026-01-01', '2026-12-25']);

    $missing = BusinessRulesDocument::initial();
    unset(
        $missing['holds']['business_days'],
        $missing['holds']['business_day_start'],
        $missing['holds']['business_day_end'],
        $missing['holds']['holidays'],
        $missing['holds']['near_term_max_days'],
    );

    $lenient = BusinessRulesDocument::fromArray($missing);

    expect($lenient->holds->businessDays)->toBe([]);
    expect($lenient->holds->businessDayStart)->toBe('');
    expect($lenient->holds->businessDayEnd)->toBe('');
    expect($lenient->holds->holidays)->toBe([]);
    expect($lenient->holds->nearTermMaxDays)->toBe(0);
});

test('fromArray sorts bands descending', function (): void {
    $document = BusinessRulesDocument::fromArray(businessRulesDocument([
        'cancellation' => [
            'bands' => [
                ['min_days' => 0, 'penalty_pct' => 100],
                ['min_days' => 90, 'penalty_pct' => 50],
                ['min_days' => 120, 'penalty_pct' => 5],
            ],
        ],
    ]));

    expect($document->bands[0]->minDays)->toBe(120);
    expect($document->bands[2]->minDays)->toBe(0);
});

test('warnings skip the engine sla check when engine settings are unpublished', function (): void {
    $draft = businessRulesDocument([
        'sla' => ['response_hours' => 12],
    ]);

    expect(app(CurrentConfig::class)->has(ConfigKind::EngineSettings))->toBeFalse();

    $messages = array_map(
        fn ($warning): string => $warning->message,
        BusinessRulesDocument::fromArray($draft)->warnings(null),
    );

    expect($messages)->not->toContain(
        'Charter page promises 24 h but the response SLA is 12 h — align in Engine Settings.',
    );
    expect($messages)->toContain(
        'OPS-009 · Quote / first-response SLA (FIT, groups, charter) differs from the CEO-confirmed value (24 hours).',
    );
});

test('warnings flag a dropping penalty and an engine sla mismatch', function (): void {
    $this->seed(ConfigSeeder::class);

    $draft = businessRulesDocument([
        'cancellation' => [
            'bands' => [
                ['min_days' => 120, 'penalty_pct' => 50],
                ['min_days' => 90, 'penalty_pct' => 5],
                ['min_days' => 0, 'penalty_pct' => 100],
            ],
        ],
        'sla' => ['response_hours' => 12],
    ]);

    $messages = array_map(
        fn ($warning): string => $warning->message,
        BusinessRulesDocument::fromArray($draft)->warnings(null),
    );

    expect($messages)->toContain('Penalty drops closer to arrival (90 days) — check the bands.');
    expect($messages)->not->toContain(
        'Charter page promises 24 h but the response SLA is 12 h — align in Engine Settings.',
    );
});

test('CurrentConfig has is false for an unpublished kind and does not cache a miss', function (): void {
    $current = app(CurrentConfig::class);

    expect($current->has(ConfigKind::BusinessRules))->toBeFalse();
    expect(cache()->has(ConfigKind::BusinessRules->cacheKey()))->toBeFalse();

    $this->seed(ConfigSeeder::class);
    $current->forget(ConfigKind::BusinessRules);

    expect($current->has(ConfigKind::BusinessRules))->toBeTrue();
    expect($current->businessRules()->commission->capPct)->toBe(12);
});
