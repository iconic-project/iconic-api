<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\ConfigKind;
use App\Services\Config\CurrentConfig;
use App\Support\Config\ConfigDocument;
use App\Support\Config\Warning;
use App\Support\Payments\CancellationPenalty;

final class BusinessRulesDocument extends ConfigDocument
{
    /**
     * @param  list<CancellationBand>  $bands
     * @param  list<CancellationBand>  $charterBands
     */
    public function __construct(
        public readonly CommissionRules $commission,
        public readonly int $modificationFeeUsd,
        public readonly PaymentsRules $payments,
        public readonly DiscountsRules $discounts,
        public readonly HoldsRules $holds,
        public readonly SlaRules $sla,
        public readonly ManifestsRules $manifests,
        public readonly AlertsRules $alerts,
        public readonly NpsRules $nps,
        public readonly RetentionRules $retention,
        public readonly ConsentVersions $consentVersions,
        public readonly LegalEntityRules $legalEntity,
        public readonly DocumentsRules $documents,
        public readonly CrmRules $crm,
        public readonly PrivacyRules $privacy,
        public readonly ReportsRules $reports,
        public readonly CharterRules $charter,
        public readonly PortalRules $portal,
        public readonly StayRules $stay,
        public readonly array $bands,
        public readonly array $charterBands,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function initial(): array
    {
        return [
            'commission' => [
                'cap_pct' => 12,
                'default_pct' => 10,
                'payable_days_after_cruise' => 30,
            ],
            'modification_fee_usd' => 0,
            'payments' => [
                'extras_due_hours' => 72,
                'wire_window_hours' => 72,
                'balance_reminder_days' => [21, 7],
            ],
            'discounts' => [
                'online_deposit_discount_pct' => 5,
                'max_total_discount_pct' => null,
            ],
            'holds' => [
                'web_minutes' => 20,
                'web_extension_minutes' => 10,
                'near_term_business_hours' => 48,
                'long_lead_business_days' => 5,
                'business_days' => [1, 2, 3, 4, 5],
                'business_day_start' => '09:00',
                'business_day_end' => '18:00',
                'holidays' => [],
                'near_term_max_days' => 120,
            ],
            'sla' => [
                'response_hours' => 24,
                'refund_business_days' => 15,
                'agency_approval_business_days' => 2,
            ],
            'manifests' => [
                'dpng_fit_days' => 15,
                'dpng_charter_days' => 30,
                'captain_days' => 7,
                'chase_days_before_due' => 10,
            ],
            'alerts' => [
                'low_occupancy_pct' => 40,
                'low_occupancy_days_before' => 90,
            ],
            'nps' => [
                'survey_hours_after_return' => 24,
                'alert_below' => 7,
                'review_request_from' => 8,
                'review_url' => 'PENDING CLIENT',
            ],
            'retention' => [
                'passport_months_after_cruise' => 24,
                'medical_days_after_cruise' => 90,
                'behavioural_raw_months' => 24,
                'behavioural_unstitched_days' => 30,
            ],
            'legal' => [
                'consent_versions' => [
                    'terms' => 'v2026.1 (text pending LEG-001)',
                    'cancellation' => 'v2026.1 (pending LEG-001)',
                    'privacy' => 'v2026.1 (pending LEG-002)',
                    'insurance' => 'OPS-005 v1',
                    'marketing' => 'v1',
                    'analytics' => 'v1 (pending LEG-002)',
                    'checkout_marketing' => 'v1 (pending LEG-002)',
                ],
            ],
            'legal_entity' => [
                'name' => 'PONTOS LLC (a limited liability company)',
                'address_lines' => [
                    '430 Grand Bay Drive, Apt 1108',
                    'Key Biscayne, FL 33149, United States',
                ],
                'email' => 'info@iconic.co',
                'website' => 'iconic.co',
                'ein' => '42-4742064',
                'bank' => [
                    'bank_name' => '[TBD]',
                    'account_name' => '[TBD]',
                    'account_number' => '[TBD]',
                    'routing' => '[TBD]',
                    'swift' => '[TBD]',
                ],
            ],
            'documents' => [
                'pretrip_days_before' => 45,
                'voucher_days_before' => 7,
            ],
            'crm' => [
                'segment_high_ltv' => 20000,
                'segment_mid_ltv' => 8000,
                'pipeline' => [
                    'sla_new_lead_business_hours' => 4,
                    'sla_qualifying_business_days' => 5,
                    'sla_negotiation_business_days' => 7,
                    'probability_new_lead' => 5,
                    'probability_qualifying' => 15,
                    'probability_quoted' => 35,
                    'probability_negotiation' => 55,
                    'probability_deposit_pending' => 80,
                ],
            ],
            'privacy' => [
                'request_sla_days' => 30,
            ],
            'reports' => [
                'retention_days' => 90,
            ],
            'charter' => [
                'deposit_business_days' => 5,
                'proposal_valid_business_days' => 10,
            ],
            'portal' => [
                'invite_valid_days' => 14,
            ],
            // TODO(OPEN: HQ3) demo value
            'stay' => [
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'no_show_cutoff_time' => '23:59',
                'min_nights' => 1,
                'max_nights' => 30,
                'max_rooms_per_booking' => 5,
                'check_in_requires_full_payment' => true,
                'booking_horizon_days' => 730,
            ],
            'cancellation' => [
                'bands' => [
                    ['min_days' => 120, 'penalty_pct' => 5],
                    ['min_days' => 90, 'penalty_pct' => 50],
                    ['min_days' => 0, 'penalty_pct' => 100],
                ],
                'charter_bands' => [
                    ['min_days' => 120, 'penalty_pct' => 5],
                    ['min_days' => 90, 'penalty_pct' => 50],
                    ['min_days' => 0, 'penalty_pct' => 100],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $commission = is_array($data['commission'] ?? null) ? $data['commission'] : [];
        $payments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        $discounts = is_array($data['discounts'] ?? null) ? $data['discounts'] : [];
        $holds = is_array($data['holds'] ?? null) ? $data['holds'] : [];
        $sla = is_array($data['sla'] ?? null) ? $data['sla'] : [];
        $manifests = is_array($data['manifests'] ?? null) ? $data['manifests'] : [];
        $alerts = is_array($data['alerts'] ?? null) ? $data['alerts'] : [];
        $nps = is_array($data['nps'] ?? null) ? $data['nps'] : [];
        $retention = is_array($data['retention'] ?? null) ? $data['retention'] : [];
        $legal = is_array($data['legal'] ?? null) ? $data['legal'] : [];
        $consentVersions = is_array($legal['consent_versions'] ?? null) ? $legal['consent_versions'] : [];
        $legalEntity = is_array($data['legal_entity'] ?? null) ? $data['legal_entity'] : [];
        $bank = is_array($legalEntity['bank'] ?? null) ? $legalEntity['bank'] : [];
        $documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $crm = is_array($data['crm'] ?? null) ? $data['crm'] : [];
        $pipeline = is_array($crm['pipeline'] ?? null) ? $crm['pipeline'] : [];
        $privacy = is_array($data['privacy'] ?? null) ? $data['privacy'] : [];
        $reports = is_array($data['reports'] ?? null) ? $data['reports'] : [];
        $charter = is_array($data['charter'] ?? null) ? $data['charter'] : [];
        $portal = is_array($data['portal'] ?? null) ? $data['portal'] : [];
        $stay = is_array($data['stay'] ?? null) ? $data['stay'] : [];
        $cancellation = is_array($data['cancellation'] ?? null) ? $data['cancellation'] : [];

        $reminders = [];
        foreach ($payments['balance_reminder_days'] ?? [] as $day) {
            if (is_numeric($day)) {
                $reminders[] = (int) $day;
            }
        }

        $maxDiscount = $discounts['max_total_discount_pct'] ?? null;
        $maxDiscount = $maxDiscount === null || $maxDiscount === '' ? null : (int) $maxDiscount;

        $bands = [];
        foreach ($cancellation['bands'] ?? [] as $band) {
            if (! is_array($band)) {
                continue;
            }

            $bands[] = new CancellationBand(
                (int) ($band['min_days'] ?? 0),
                (int) ($band['penalty_pct'] ?? 0),
            );
        }

        usort($bands, fn (CancellationBand $a, CancellationBand $b): int => $b->minDays <=> $a->minDays);

        $charterBands = [];
        foreach ($cancellation['charter_bands'] ?? [] as $band) {
            if (! is_array($band)) {
                continue;
            }

            $charterBands[] = new CancellationBand(
                (int) ($band['min_days'] ?? 0),
                (int) ($band['penalty_pct'] ?? 0),
            );
        }

        usort($charterBands, fn (CancellationBand $a, CancellationBand $b): int => $b->minDays <=> $a->minDays);

        return new self(
            new CommissionRules(
                (int) ($commission['cap_pct'] ?? 0),
                (int) ($commission['default_pct'] ?? 0),
                (int) ($commission['payable_days_after_cruise'] ?? 0),
            ),
            (int) ($data['modification_fee_usd'] ?? 0),
            new PaymentsRules(
                (int) ($payments['extras_due_hours'] ?? 0),
                (int) ($payments['wire_window_hours'] ?? 0),
                $reminders,
            ),
            new DiscountsRules(
                (int) ($discounts['online_deposit_discount_pct'] ?? 0),
                $maxDiscount,
            ),
            new HoldsRules(
                (int) ($holds['web_minutes'] ?? 0),
                (int) ($holds['web_extension_minutes'] ?? 0),
                (int) ($holds['near_term_business_hours'] ?? 0),
                (int) ($holds['long_lead_business_days'] ?? 0),
                self::intList($holds['business_days'] ?? []),
                is_string($holds['business_day_start'] ?? null) ? $holds['business_day_start'] : '',
                is_string($holds['business_day_end'] ?? null) ? $holds['business_day_end'] : '',
                self::dateList($holds['holidays'] ?? []),
                (int) ($holds['near_term_max_days'] ?? 0),
            ),
            new SlaRules(
                (int) ($sla['response_hours'] ?? 0),
                (int) ($sla['refund_business_days'] ?? 0),
                (int) ($sla['agency_approval_business_days'] ?? 0),
            ),
            new ManifestsRules(
                (int) ($manifests['dpng_fit_days'] ?? 0),
                (int) ($manifests['dpng_charter_days'] ?? 0),
                (int) ($manifests['captain_days'] ?? 0),
                (int) ($manifests['chase_days_before_due'] ?? 0),
            ),
            new AlertsRules(
                (int) ($alerts['low_occupancy_pct'] ?? 0),
                (int) ($alerts['low_occupancy_days_before'] ?? 0),
            ),
            new NpsRules(
                (int) ($nps['survey_hours_after_return'] ?? 0),
                (int) ($nps['alert_below'] ?? 0),
                (int) ($nps['review_request_from'] ?? 0),
                is_string($nps['review_url'] ?? null) ? $nps['review_url'] : '',
            ),
            new RetentionRules(
                (int) ($retention['passport_months_after_cruise'] ?? 0),
                (int) ($retention['medical_days_after_cruise'] ?? 0),
                (int) ($retention['behavioural_raw_months'] ?? 0),
                (int) ($retention['behavioural_unstitched_days'] ?? 0),
            ),
            new ConsentVersions(
                is_string($consentVersions['terms'] ?? null) ? $consentVersions['terms'] : '',
                is_string($consentVersions['cancellation'] ?? null) ? $consentVersions['cancellation'] : '',
                is_string($consentVersions['privacy'] ?? null) ? $consentVersions['privacy'] : '',
                is_string($consentVersions['insurance'] ?? null) ? $consentVersions['insurance'] : '',
                is_string($consentVersions['marketing'] ?? null) ? $consentVersions['marketing'] : '',
                is_string($consentVersions['analytics'] ?? null) ? $consentVersions['analytics'] : '',
                is_string($consentVersions['checkout_marketing'] ?? null) ? $consentVersions['checkout_marketing'] : '',
            ),
            new LegalEntityRules(
                is_string($legalEntity['name'] ?? null) ? $legalEntity['name'] : '',
                self::stringList($legalEntity['address_lines'] ?? []),
                is_string($legalEntity['email'] ?? null) ? $legalEntity['email'] : '',
                is_string($legalEntity['website'] ?? null) ? $legalEntity['website'] : '',
                is_string($legalEntity['ein'] ?? null) ? $legalEntity['ein'] : '',
                new BankRules(
                    self::bankValue($bank['bank_name'] ?? null),
                    self::bankValue($bank['account_name'] ?? null),
                    self::bankValue($bank['account_number'] ?? null),
                    self::bankValue($bank['routing'] ?? null),
                    self::bankValue($bank['swift'] ?? null),
                ),
            ),
            new DocumentsRules(
                (int) ($documents['pretrip_days_before'] ?? 0),
                (int) ($documents['voucher_days_before'] ?? 0),
            ),
            new CrmRules(
                (int) ($crm['segment_high_ltv'] ?? 0),
                (int) ($crm['segment_mid_ltv'] ?? 0),
                new PipelineRules(
                    (int) ($pipeline['sla_new_lead_business_hours'] ?? 0),
                    (int) ($pipeline['sla_qualifying_business_days'] ?? 0),
                    (int) ($pipeline['sla_negotiation_business_days'] ?? 0),
                    (int) ($pipeline['probability_new_lead'] ?? 0),
                    (int) ($pipeline['probability_qualifying'] ?? 0),
                    (int) ($pipeline['probability_quoted'] ?? 0),
                    (int) ($pipeline['probability_negotiation'] ?? 0),
                    (int) ($pipeline['probability_deposit_pending'] ?? 0),
                ),
            ),
            new PrivacyRules(
                (int) ($privacy['request_sla_days'] ?? 0),
            ),
            new ReportsRules(
                (int) ($reports['retention_days'] ?? 0),
            ),
            new CharterRules(
                (int) ($charter['deposit_business_days'] ?? 0),
                (int) ($charter['proposal_valid_business_days'] ?? 0),
            ),
            new PortalRules(
                (int) ($portal['invite_valid_days'] ?? 0),
            ),
            new StayRules(
                is_string($stay['check_in_time'] ?? null) ? $stay['check_in_time'] : '',
                is_string($stay['check_out_time'] ?? null) ? $stay['check_out_time'] : '',
                is_string($stay['no_show_cutoff_time'] ?? null) ? $stay['no_show_cutoff_time'] : '',
                (int) ($stay['min_nights'] ?? 0),
                (int) ($stay['max_nights'] ?? 0),
                (int) ($stay['max_rooms_per_booking'] ?? 0),
                self::flag($stay['check_in_requires_full_payment'] ?? false),
                (int) ($stay['booking_horizon_days'] ?? 0),
            ),
            $bands,
            $charterBands,
        );
    }

    /**
     * @return array{
     *     commission: array{cap_pct: int, default_pct: int, payable_days_after_cruise: int},
     *     modification_fee_usd: int,
     *     payments: array{extras_due_hours: int, wire_window_hours: int, balance_reminder_days: list<int>},
     *     discounts: array{online_deposit_discount_pct: int, max_total_discount_pct: int|null},
     *     holds: array{web_minutes: int, web_extension_minutes: int, near_term_business_hours: int, long_lead_business_days: int, business_days: list<int>, business_day_start: string, business_day_end: string, holidays: list<string>, near_term_max_days: int},
     *     sla: array{response_hours: int, refund_business_days: int, agency_approval_business_days: int},
     *     manifests: array{dpng_fit_days: int, dpng_charter_days: int, captain_days: int, chase_days_before_due: int},
     *     alerts: array{low_occupancy_pct: int, low_occupancy_days_before: int},
     *     nps: array{survey_hours_after_return: int, alert_below: int, review_request_from: int, review_url: string},
     *     retention: array{passport_months_after_cruise: int, medical_days_after_cruise: int, behavioural_raw_months: int, behavioural_unstitched_days: int},
     *     legal: array{consent_versions: array{terms: string, cancellation: string, privacy: string, insurance: string, marketing: string, analytics: string, checkout_marketing: string}},
     *     legal_entity: array{name: string, address_lines: list<string>, email: string, website: string, ein: string, bank: array{bank_name: string, account_name: string, account_number: string, routing: string, swift: string}},
     *     documents: array{pretrip_days_before: int, voucher_days_before: int},
     *     crm: array{segment_high_ltv: int, segment_mid_ltv: int, pipeline: array{sla_new_lead_business_hours: int, sla_qualifying_business_days: int, sla_negotiation_business_days: int, probability_new_lead: int, probability_qualifying: int, probability_quoted: int, probability_negotiation: int, probability_deposit_pending: int}},
     *     privacy: array{request_sla_days: int},
     *     reports: array{retention_days: int},
     *     charter: array{deposit_business_days: int, proposal_valid_business_days: int},
     *     portal: array{invite_valid_days: int},
     *     stay: array{check_in_time: string, check_out_time: string, no_show_cutoff_time: string, min_nights: int, max_nights: int, max_rooms_per_booking: int, check_in_requires_full_payment: bool, booking_horizon_days: int},
     *     cancellation: array{bands: list<array{min_days: int, penalty_pct: int}>, charter_bands: list<array{min_days: int, penalty_pct: int}>}
     * }
     */
    public function toArray(): array
    {
        return [
            'commission' => $this->commission->toArray(),
            'modification_fee_usd' => $this->modificationFeeUsd,
            'payments' => $this->payments->toArray(),
            'discounts' => $this->discounts->toArray(),
            'holds' => $this->holds->toArray(),
            'sla' => $this->sla->toArray(),
            'manifests' => $this->manifests->toArray(),
            'alerts' => $this->alerts->toArray(),
            'nps' => $this->nps->toArray(),
            'retention' => $this->retention->toArray(),
            'legal' => [
                'consent_versions' => $this->consentVersions->toArray(),
            ],
            'legal_entity' => $this->legalEntity->toArray(),
            'documents' => $this->documents->toArray(),
            'crm' => $this->crm->toArray(),
            'privacy' => $this->privacy->toArray(),
            'reports' => $this->reports->toArray(),
            'charter' => $this->charter->toArray(),
            'portal' => $this->portal->toArray(),
            'stay' => $this->stay->toArray(),
            'cancellation' => [
                'bands' => array_map(
                    fn (CancellationBand $band): array => $band->toArray(),
                    $this->bands,
                ),
                'charter_bands' => array_map(
                    fn (CancellationBand $band): array => $band->toArray(),
                    $this->charterBands,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'commission' => ['required', 'array'],
            'commission.cap_pct' => ['required', 'integer', 'min:0', 'max:30'],
            'commission.default_pct' => ['required', 'integer', 'min:0', 'max:30', new BusinessRulesConstraint('default_lte_cap')],
            'commission.payable_days_after_cruise' => ['required', 'integer', 'min:0', 'max:120'],
            'modification_fee_usd' => ['required', 'integer', 'min:0', 'max:10000'],
            'payments' => ['required', 'array'],
            'payments.extras_due_hours' => ['required', 'integer', 'min:0', 'max:2160'],
            'payments.wire_window_hours' => ['required', 'integer', 'min:12', 'max:168'],
            'payments.balance_reminder_days' => ['required', 'array', 'size:2', new BusinessRulesConstraint('reminders_decreasing')],
            'payments.balance_reminder_days.*' => ['required', 'integer', 'min:1', 'max:60'],
            'discounts' => ['required', 'array'],
            'discounts.online_deposit_discount_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'discounts.max_total_discount_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'holds' => ['required', 'array'],
            'holds.web_minutes' => ['required', 'integer', 'min:5', 'max:60'],
            'holds.web_extension_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'holds.near_term_business_hours' => ['required', 'integer', 'min:4', 'max:120'],
            'holds.long_lead_business_days' => ['required', 'integer', 'min:1', 'max:15'],
            'holds.business_days' => ['required', 'array', 'min:1', 'distinct'],
            'holds.business_days.*' => ['required', 'integer', 'min:1', 'max:7'],
            'holds.business_day_start' => ['required', 'date_format:H:i'],
            'holds.business_day_end' => ['required', 'date_format:H:i', new BusinessRulesConstraint('day_end_after_start')],
            'holds.holidays' => ['present', 'array', 'distinct'],
            'holds.holidays.*' => ['date_format:Y-m-d'],
            'holds.near_term_max_days' => ['required', 'integer', 'min:1', 'max:365'],
            'sla' => ['required', 'array'],
            'sla.response_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'sla.refund_business_days' => ['required', 'integer', 'min:1', 'max:60'],
            'sla.agency_approval_business_days' => ['required', 'integer', 'min:1', 'max:10'],
            'manifests' => ['required', 'array'],
            'manifests.dpng_fit_days' => ['required', 'integer', 'min:1', 'max:90'],
            'manifests.dpng_charter_days' => ['required', 'integer', 'min:1', 'max:90'],
            'manifests.captain_days' => ['required', 'integer', 'min:1', 'max:90'],
            'manifests.chase_days_before_due' => ['required', 'integer', 'min:1', 'max:90'],
            'alerts' => ['required', 'array'],
            'alerts.low_occupancy_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'alerts.low_occupancy_days_before' => ['required', 'integer', 'min:1', 'max:365'],
            'nps' => ['required', 'array'],
            'nps.survey_hours_after_return' => ['required', 'integer', 'min:1', 'max:168'],
            'nps.alert_below' => ['required', 'integer', 'min:1', 'max:10'],
            'nps.review_request_from' => ['required', 'integer', 'min:0', 'max:10'],
            'nps.review_url' => ['required', 'string', 'min:1', 'max:200'],
            'retention' => ['required', 'array'],
            'retention.passport_months_after_cruise' => ['required', 'integer', 'min:1', 'max:120'],
            'retention.medical_days_after_cruise' => ['required', 'integer', 'min:1', 'max:3650'],
            'retention.behavioural_raw_months' => ['required', 'integer', 'min:1', 'max:120'],
            'retention.behavioural_unstitched_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'reports' => ['required', 'array'],
            'reports.retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'charter' => ['required', 'array'],
            'charter.deposit_business_days' => ['required', 'integer', 'min:1', 'max:60'],
            'charter.proposal_valid_business_days' => ['required', 'integer', 'min:1', 'max:60'],
            'portal' => ['required', 'array'],
            'portal.invite_valid_days' => ['required', 'integer', 'min:1', 'max:60'],
            'stay' => ['required', 'array'],
            'stay.check_in_time' => ['required', 'date_format:H:i'],
            'stay.check_out_time' => ['required', 'date_format:H:i'],
            'stay.no_show_cutoff_time' => ['required', 'date_format:H:i'],
            'stay.min_nights' => ['required', 'integer', 'min:1'],
            'stay.max_nights' => ['required', 'integer', 'min:1', 'max:365', new BusinessRulesConstraint('max_nights_gte_min')],
            'stay.max_rooms_per_booking' => ['required', 'integer', 'min:1', 'max:50'],
            'stay.check_in_requires_full_payment' => ['required', 'boolean'],
            'stay.booking_horizon_days' => ['required', 'integer', 'min:30', 'max:1095'],
            'legal' => ['required', 'array'],
            'legal.consent_versions' => ['required', 'array'],
            'legal.consent_versions.terms' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.cancellation' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.privacy' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.insurance' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.marketing' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.analytics' => ['required', 'string', 'min:1', 'max:120'],
            'legal.consent_versions.checkout_marketing' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity' => ['required', 'array'],
            'legal_entity.name' => ['required', 'string', 'min:1', 'max:180'],
            'legal_entity.address_lines' => ['required', 'array', 'min:1', 'max:6'],
            'legal_entity.address_lines.*' => ['required', 'string', 'min:1', 'max:180'],
            'legal_entity.email' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity.website' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity.ein' => ['required', 'string', 'min:1', 'max:32'],
            'legal_entity.bank' => ['required', 'array'],
            'legal_entity.bank.bank_name' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity.bank.account_name' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity.bank.account_number' => ['required', 'string', 'min:1', 'max:120'],
            'legal_entity.bank.routing' => ['required', 'string', 'min:1', 'max:64'],
            'legal_entity.bank.swift' => ['required', 'string', 'min:1', 'max:32'],
            'documents' => ['required', 'array'],
            'documents.pretrip_days_before' => ['required', 'integer', 'min:1', 'max:120'],
            'documents.voucher_days_before' => ['required', 'integer', 'min:1', 'max:60'],
            'crm' => ['required', 'array'],
            'crm.segment_high_ltv' => ['required', 'integer', 'min:1', 'max:1000000'],
            'crm.segment_mid_ltv' => ['required', 'integer', 'min:0', 'max:1000000'],
            'crm.pipeline' => ['required', 'array'],
            'crm.pipeline.sla_new_lead_business_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'crm.pipeline.sla_qualifying_business_days' => ['required', 'integer', 'min:1', 'max:60'],
            'crm.pipeline.sla_negotiation_business_days' => ['required', 'integer', 'min:1', 'max:60'],
            'crm.pipeline.probability_new_lead' => ['required', 'integer', 'min:0', 'max:100'],
            'crm.pipeline.probability_qualifying' => ['required', 'integer', 'min:0', 'max:100'],
            'crm.pipeline.probability_quoted' => ['required', 'integer', 'min:0', 'max:100'],
            'crm.pipeline.probability_negotiation' => ['required', 'integer', 'min:0', 'max:100'],
            'crm.pipeline.probability_deposit_pending' => ['required', 'integer', 'min:0', 'max:100'],
            'privacy' => ['required', 'array'],
            'privacy.request_sla_days' => ['required', 'integer', 'min:1', 'max:365'],
            'cancellation' => ['required', 'array'],
            'cancellation.bands' => ['required', 'array', 'min:1', 'max:6', new BusinessRulesConstraint('bands')],
            'cancellation.bands.*.min_days' => ['required', 'integer', 'min:0', 'max:999'],
            'cancellation.bands.*.penalty_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'cancellation.charter_bands' => ['required', 'array', 'min:1', 'max:6', new BusinessRulesConstraint('bands')],
            'cancellation.charter_bands.*.min_days' => ['required', 'integer', 'min:0', 'max:999'],
            'cancellation.charter_bands.*.penalty_pct' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'commission.cap_pct' => 'FIN-005 · Max agency commission',
            'commission.default_pct' => 'RMS · Default agency commission',
            'commission.payable_days_after_cruise' => '§10 · Commission payable after cruise',
            'modification_fee_usd' => 'FIN-006 · Date-change / modification fee',
            'payments.extras_due_hours' => 'Iconic · Extras & collected fees — due before departure',
            'payments.wire_window_hours' => 'RMS · Wire transfer window before auto-release',
            'payments.balance_reminder_days' => '§4.1.4 · Balance reminders — days before due',
            'discounts.online_deposit_discount_pct' => '08 B2 · Online-deposit advantage',
            'discounts.max_total_discount_pct' => '08 B2 · Max total discount',
            'holds.web_minutes' => 'R-B2 · Web checkout hold (+ one silent extension)',
            'holds.web_extension_minutes' => 'R-B2 · Web checkout hold (+ one silent extension)',
            'holds.near_term_business_hours' => 'TEC-004 · Request / agency hold — near-term',
            'holds.long_lead_business_days' => 'TEC-004 · Request / agency hold — long-lead',
            'holds.business_days' => 'TEC-004 · Business days',
            'holds.business_day_start' => 'TEC-004 · Business day start',
            'holds.business_day_end' => 'TEC-004 · Business day end',
            'holds.holidays' => 'TEC-004 · Holidays',
            'holds.near_term_max_days' => 'TEC-004 · Near-term window',
            'sla.response_hours' => 'OPS-009 · Quote / first-response SLA (FIT, groups, charter)',
            'sla.refund_business_days' => 'RMS · Refund execution SLA',
            'sla.agency_approval_business_days' => '§5.5 · Agency approval SLA',
            'manifests.dpng_fit_days' => 'OPS-013 · DPNG manifest deadline — FIT / charter',
            'manifests.dpng_charter_days' => 'OPS-013 · DPNG manifest deadline — FIT / charter',
            'manifests.captain_days' => 'N4 · Captain\'s manifest deadline',
            'manifests.chase_days_before_due' => 'N5 · Passenger-data chaser — days before the DPNG due date',
            'alerts.low_occupancy_pct' => '§10 · Low-occupancy alert',
            'alerts.low_occupancy_days_before' => '§10 · Low-occupancy alert',
            'nps.survey_hours_after_return' => 'N8 · Survey hours after return',
            'nps.alert_below' => 'N8 · NPS alert below',
            'nps.review_request_from' => 'N8 · Review request from',
            'nps.review_url' => 'LEG-002 · Public review URL',
            'retention.passport_months_after_cruise' => '§6.4 · Passport retention',
            'retention.medical_days_after_cruise' => 'LEG-002 · Medical notes retention',
            'retention.behavioural_raw_months' => 'L6 · Behavioural events raw retention',
            'retention.behavioural_unstitched_days' => 'L6 · Unstitched anonymous events retention',
            'reports.retention_days' => 'O2 · Generated report file retention',
            'charter.deposit_business_days' => 'FIN-003 · Charter deposit due in business days',
            'charter.proposal_valid_business_days' => 'O5 · Charter proposal validity',
            'portal.invite_valid_days' => '§5.5 · Portal invitation validity',
            'stay.check_in_time' => 'Stay · Check-in time',
            'stay.check_out_time' => 'Stay · Check-out time',
            'stay.no_show_cutoff_time' => 'Stay · No-show cutoff',
            'stay.min_nights' => 'Stay · Minimum nights',
            'stay.max_nights' => 'Stay · Maximum nights',
            'stay.max_rooms_per_booking' => 'Stay · Maximum rooms per booking',
            'stay.check_in_requires_full_payment' => 'Stay · Check-in requires full payment',
            'stay.booking_horizon_days' => 'Stay · Booking horizon',
            'cancellation.charter_bands' => 'O6 · Charter cancellation penalty bands',
            'legal.consent_versions.terms' => 'LEG-001 · Terms & Conditions version',
            'legal.consent_versions.cancellation' => 'LEG-001 · Cancellation policy version',
            'legal.consent_versions.privacy' => 'LEG-002 · Privacy policy version',
            'legal.consent_versions.insurance' => 'OPS-005 · Travel insurance declaration version',
            'legal.consent_versions.marketing' => 'LEG-002 · Marketing consent version',
            'legal.consent_versions.analytics' => 'LEG-002 · Analytics consent version',
            'legal.consent_versions.checkout_marketing' => 'LEG-002 · Checkout marketing consent version',
            'legal_entity.name' => 'Decision 8 · Invoicing entity',
            'legal_entity.address_lines' => 'Decision 8 · Invoicing entity address',
            'legal_entity.email' => 'Decision 8 · Invoicing entity email',
            'legal_entity.website' => 'Decision 8 · Invoicing entity website',
            'legal_entity.ein' => 'Decision 8 · EIN',
            'legal_entity.bank.bank_name' => 'LEG-004 · Bank name',
            'legal_entity.bank.account_name' => 'LEG-004 · Account name',
            'legal_entity.bank.account_number' => 'LEG-004 · Account number',
            'legal_entity.bank.routing' => 'LEG-004 · Routing number',
            'legal_entity.bank.swift' => 'LEG-004 · SWIFT',
            'documents.pretrip_days_before' => 'J7 · Pre-trip itinerary days before departure',
            'documents.voucher_days_before' => 'J7 · Transfer voucher days before departure',
            'crm.segment_high_ltv' => 'L2 · CRM segment HIGH lifetime-value threshold',
            'crm.segment_mid_ltv' => 'L2 · CRM segment MID lifetime-value threshold',
            'crm.pipeline.sla_new_lead_business_hours' => 'M4 · New lead SLA',
            'crm.pipeline.sla_qualifying_business_days' => 'M4 · Qualifying SLA',
            'crm.pipeline.sla_negotiation_business_days' => 'M4 · Negotiation SLA',
            'crm.pipeline.probability_new_lead' => 'M4 · New lead probability',
            'crm.pipeline.probability_qualifying' => 'M4 · Qualifying probability',
            'crm.pipeline.probability_quoted' => 'M4 · Quoted probability',
            'crm.pipeline.probability_negotiation' => 'M4 · Negotiation probability',
            'crm.pipeline.probability_deposit_pending' => 'M4 · Deposit pending probability',
            'privacy.request_sla_days' => 'M7 · Subject request SLA',
            'cancellation.bands' => '§4.1.5 · Cabin cancellation penalty bands',
        ];
    }

    public static function kind(): ConfigKind
    {
        return ConfigKind::BusinessRules;
    }

    public function penaltyFor(int $daysBeforeDeparture): CancellationBand
    {
        $band = CancellationPenalty::bandFor($daysBeforeDeparture, $this->bands);

        return new CancellationBand($band['min_days'], $band['penalty_pct']);
    }

    public function charterPenaltyFor(int $daysBeforeDeparture): CancellationBand
    {
        $band = CancellationPenalty::bandFor($daysBeforeDeparture, $this->charterBands);

        return new CancellationBand($band['min_days'], $band['penalty_pct']);
    }

    /**
     * @return list<Warning>
     */
    public function warnings(?ConfigDocument $published): array
    {
        $warnings = [];

        if ($this->manifests->dpngCharterDays < $this->manifests->dpngFitDays) {
            $warnings[] = new Warning(
                'manifests.dpng_charter_days',
                'Charter manifest deadline is shorter than FIT — the source has charter earlier (30 vs 15 days).',
            );
        }

        $sorted = $this->bands;
        for ($i = 1, $count = count($sorted); $i < $count; $i++) {
            if ($sorted[$i]->penaltyPct < $sorted[$i - 1]->penaltyPct) {
                $warnings[] = new Warning(
                    'cancellation.bands',
                    'Penalty drops closer to departure ('.$sorted[$i]->minDays.' days) — check the bands.',
                );
            }
        }

        $current = app(CurrentConfig::class);

        if ($current->has(ConfigKind::EngineSettings)) {
            $engineSla = $current->engineSettings()->charter->responseSlaHours;

            if ($engineSla !== $this->sla->responseHours) {
                $warnings[] = new Warning(
                    'sla.response_hours',
                    'Charter page promises '.$engineSla.' h but the response SLA is '.$this->sla->responseHours.' h — align in Engine Settings.',
                );
            }
        }

        if ($this->stay->checkOutTime > $this->stay->checkInTime) {
            $warnings[] = new Warning(
                'stay.check_out_time',
                'Check-out time is later than check-in time — a room cannot be turned over on the same day.',
            );
        }

        $source = self::fromArray(self::initial());
        $seen = [];

        foreach ($this->changesAgainst($source) as $change) {
            if (isset($seen[$change->label])) {
                continue;
            }

            $seen[$change->label] = true;
            $warnings[] = new Warning(
                $change->path,
                $change->label.' differs from the CEO-confirmed value ('.self::sourceDisplay($change->path).').',
            );
        }

        return $warnings;
    }

    public static function sourceDisplay(string $path): string
    {
        return match ($path) {
            'commission.cap_pct' => '12%',
            'commission.default_pct' => '10% (confirmed 12 Sep 2026)',
            'commission.payable_days_after_cruise' => '30 days',
            'modification_fee_usd' => 'USD 0 — free, subject to availability',
            'payments.extras_due_hours' => '72 hours (Iconic 12 Sep 2026); services taken on board are settled during / after the cruise',
            'payments.wire_window_hours' => '72 hours (confirmed 12 Sep 2026)',
            'payments.balance_reminder_days' => '21 and 7 days',
            'discounts.online_deposit_discount_pct' => '5%',
            'discounts.max_total_discount_pct' => 'no cap',
            'holds.web_minutes', 'holds.web_extension_minutes' => '20 / 10 min (confirmed 12 Sep 2026)',
            'holds.near_term_business_hours' => '48 business hours',
            'holds.long_lead_business_days' => '5 business days',
            'holds.business_days',
            'holds.business_day_start',
            'holds.business_day_end',
            'holds.holidays',
            'holds.near_term_max_days' => 'Not defined in v5 — default',
            'sla.response_hours' => '24 hours',
            'sla.refund_business_days' => '15 business days (confirmed 12 Sep 2026)',
            'sla.agency_approval_business_days' => '2 business days',
            'manifests.dpng_fit_days', 'manifests.dpng_charter_days' => '15 / 30 days',
            'manifests.captain_days' => '7 days (N4 / prototype T−7)',
            'manifests.chase_days_before_due' => '10 days (PENDING CLIENT, N5)',
            'alerts.low_occupancy_pct', 'alerts.low_occupancy_days_before' => '40% at 90 days',
            'nps.survey_hours_after_return',
            'nps.alert_below',
            'nps.review_request_from' => '24 h after return · alert below 7 · review from 8 (N8)',
            'nps.review_url' => 'PENDING CLIENT (LEG-002)',
            'retention.passport_months_after_cruise' => '24 months',
            'retention.medical_days_after_cruise' => '90 days',
            'retention.behavioural_raw_months' => '24 months (PENDING CLIENT, L6 / doc 07 §8)',
            'retention.behavioural_unstitched_days' => '30 days (PENDING CLIENT, L6)',
            'reports.retention_days' => '90 days (PENDING CLIENT, O2)',
            'charter.deposit_business_days' => '5 business days (FIN-003)',
            'charter.proposal_valid_business_days' => '10 business days (PENDING CLIENT, O5)',
            'portal.invite_valid_days' => '14 days (PENDING CLIENT, Sprint 13 task 01)',
            'stay.check_in_time' => '15:00 (demo, HQ3)',
            'stay.check_out_time' => '11:00 (demo, HQ3)',
            'stay.no_show_cutoff_time' => '23:59 (demo, HQ3)',
            'stay.min_nights' => '1 night (demo, HQ3)',
            'stay.max_nights' => '30 nights (demo, HQ3)',
            'stay.max_rooms_per_booking' => '5 rooms (demo, HQ3)',
            'stay.check_in_requires_full_payment' => 'Yes (demo, HQ3)',
            'stay.booking_horizon_days' => '730 days (demo, HQ3)',
            'cancellation.charter_bands' => '≥120 d 5% · 90–119 d 50% · 0–89 d 100% (PENDING CLIENT, O6, copies the cabin bands)',
            'legal.consent_versions.terms' => 'v2026.1 (text pending LEG-001)',
            'legal.consent_versions.cancellation' => 'v2026.1 (pending LEG-001)',
            'legal.consent_versions.privacy' => 'v2026.1 (pending LEG-002)',
            'legal.consent_versions.insurance' => 'OPS-005 v1',
            'legal.consent_versions.marketing' => 'v1',
            'legal.consent_versions.analytics' => 'v1 (pending LEG-002)',
            'legal.consent_versions.checkout_marketing' => 'v1 (pending LEG-002)',
            'legal_entity.name' => 'PONTOS LLC (a limited liability company)',
            'legal_entity.address_lines' => '430 Grand Bay Drive, Apt 1108 · Key Biscayne, FL 33149, United States',
            'legal_entity.email' => 'info@iconic.co',
            'legal_entity.website' => 'iconic.co',
            'legal_entity.ein' => '42-4742064',
            'legal_entity.bank.bank_name',
            'legal_entity.bank.account_name',
            'legal_entity.bank.account_number',
            'legal_entity.bank.routing',
            'legal_entity.bank.swift' => '[TBD] (LEG-004 pending client)',
            'documents.pretrip_days_before' => '45 days (J7 / prototype T−45)',
            'documents.voucher_days_before' => '7 days (J7 / prototype T−7)',
            'crm.segment_high_ltv' => 'USD 20,000 (PENDING CLIENT, prototype segOf)',
            'crm.segment_mid_ltv' => 'USD 8,000 (PENDING CLIENT, prototype segOf)',
            'crm.pipeline.sla_new_lead_business_hours' => '4 business hours (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.sla_qualifying_business_days' => '5 business days (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.sla_negotiation_business_days' => '7 business days (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.probability_new_lead' => '5% (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.probability_qualifying' => '15% (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.probability_quoted' => '35% (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.probability_negotiation' => '55% (PENDING CLIENT, prototype pipeline)',
            'crm.pipeline.probability_deposit_pending' => '80% (PENDING CLIENT, prototype pipeline)',
            'privacy.request_sla_days' => '30 calendar days (PENDING LEG-002)',
            'cancellation.bands' => '≥120 d 5% · 90–119 d 50% · 0–89 d 100%',
            default => $path,
        };
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $days = [];

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $days[] = (int) $item;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * @return list<string>
     */
    private static function dateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $dates = [];

        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item) === 1) {
                $dates[] = $item;
            }
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $lines[] = $item;
            }
        }

        return $lines;
    }

    private static function bankValue(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '[TBD]';
    }
}
