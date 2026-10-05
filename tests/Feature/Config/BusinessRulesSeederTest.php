<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;

test('the seeded business rules document matches seed-data.json plus the new fields', function (): void {
    $this->seed(ConfigSeeder::class);

    $path = base_path('docs/requirements/examples/seed-data.json');
    /** @var array{policies: array<string, mixed>, cancellation_bands: list<array{min: int, pct: int}>} $seed */
    $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $policies = $seed['policies'];
    $document = BusinessRulesDocument::initial();

    expect($document['commission']['cap_pct'])->toBe($policies['commCap']);
    expect($document['commission']['default_pct'])->toBe($policies['commDefault']);
    expect($document['commission']['payable_days_after_check_out'])->toBe(30);
    expect($document['modification_fee_usd'])->toBe($policies['modFee']);
    expect($document['payments']['extras_due_hours'])->toBe($policies['extrasDueH']);
    expect($document['payments']['wire_window_hours'])->toBe($policies['wireHours']);
    expect($document['payments']['balance_reminder_days'])->toBe([$policies['remind1'], $policies['remind2']]);
    expect($document['discounts']['online_deposit_discount_pct'])->toBe(5);
    expect($document['discounts']['max_total_discount_pct'])->toBeNull();
    expect($document['holds']['web_minutes'])->toBe($policies['webHoldMin']);
    expect($document['holds']['web_extension_minutes'])->toBe($policies['webExtMin']);
    expect($document['holds']['near_term_business_hours'])->toBe($policies['holdNearH']);
    expect($document['holds']['long_lead_business_days'])->toBe($policies['holdLongD']);
    expect($document['holds']['business_days'])->toBe([1, 2, 3, 4, 5]);
    expect($document['holds']['business_day_start'])->toBe('09:00');
    expect($document['holds']['business_day_end'])->toBe('18:00');
    expect($document['holds']['holidays'])->toBe([]);
    expect($document['holds']['near_term_max_days'])->toBe(120);
    expect($document['sla']['response_hours'])->toBe($policies['reqSla']);
    expect($document['sla']['refund_business_days'])->toBe($policies['refundSla']);
    expect($document['sla']['agency_approval_business_days'])->toBe(2);
    expect($document['manifests']['dpng_fit_days'])->toBe($policies['manifestFit']);
    expect($document['manifests']['dpng_charter_days'])->toBe($policies['manifestCh']);
    expect($document['alerts']['low_occupancy_pct'])->toBe(40);
    expect($document['alerts']['low_occupancy_days_before'])->toBe(90);
    expect($document['nps']['survey_hours_after_return'])->toBe(24);
    expect($document['nps']['alert_below'])->toBe(7);
    expect($document['nps']['review_request_from'])->toBe(8);
    expect($document['nps']['review_url'])->toBe('PENDING CLIENT');
    expect($document['retention']['passport_months_after_cruise'])->toBe(24);
    expect($document['retention']['medical_days_after_cruise'])->toBe(90);
    expect($document['retention']['behavioural_raw_months'])->toBe(24);
    expect($document['retention']['behavioural_unstitched_days'])->toBe(30);
    expect($document['legal_entity']['name'])->toBe('PONTOS LLC (a limited liability company)');
    expect($document['legal_entity']['address_lines'])->toBe([
        '430 Grand Bay Drive, Apt 1108',
        'Key Biscayne, FL 33149, United States',
    ]);
    expect($document['legal_entity']['email'])->toBe('info@iconic.co');
    expect($document['legal_entity']['website'])->toBe('iconic.co');
    expect($document['legal_entity']['ein'])->toBe('42-4742064');
    expect($document['legal_entity']['bank'])->toBe([
        'bank_name' => '[TBD]',
        'account_name' => '[TBD]',
        'account_number' => '[TBD]',
        'routing' => '[TBD]',
        'swift' => '[TBD]',
    ]);
    expect($document['documents']['pretrip_days_before'])->toBe(45);
    expect($document['documents']['voucher_days_before'])->toBe(7);
    expect($document['crm']['segment_high_ltv'])->toBe(20000);
    expect($document['crm']['segment_mid_ltv'])->toBe(8000);
    expect($document['stay'])->toBe([
        'check_in_time' => '15:00',
        'check_out_time' => '11:00',
        'no_show_cutoff_time' => '23:59',
        'min_nights' => 1,
        'max_nights' => 30,
        'max_rooms_per_booking' => 5,
        'check_in_requires_full_payment' => true,
        'booking_horizon_days' => 730,
    ]);

    $bands = array_map(
        fn (array $band): array => ['min_days' => $band['min'], 'penalty_pct' => $band['pct']],
        $seed['cancellation_bands'],
    );
    expect($document['cancellation']['bands'])->toBe($bands);
    expect($document['cancellation']['bands'])->toBe(array_map(
        fn (array $band): array => ['min_days' => $band['min'], 'penalty_pct' => $band['pct']],
        $policies['cancel'],
    ));

    $row = BusinessRuleVersion::query()->firstOrFail();
    expect($row->version)->toBe(1);
    expect($row->asDocument()->toArray())->toBe($document);
    expect(app(CurrentConfig::class)->businessRules()->toArray())->toBe($document);
});

test('the business rules seeder is idempotent', function (): void {
    $this->seed(ConfigSeeder::class);
    $this->seed(ConfigSeeder::class);

    expect(BusinessRuleVersion::query()->count())->toBe(1);
});
