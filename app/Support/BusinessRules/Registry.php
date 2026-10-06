<?php

declare(strict_types=1);

namespace App\Support\BusinessRules;

use App\Enums\ConfigKind;
use App\Enums\RuleGroup;
use App\Enums\RuleStatus;
use App\Enums\RuleWhere;
use App\Services\Config\CurrentConfig;
use App\Support\Config\DocumentDiff;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Money;

final class Registry
{
    private const LINK_RATES = '/rms/commercial/rates';

    private const LINK_ENGINE = '/rms/booking-engine/settings';

    /**
     * @return list<RuleDefinition>
     */
    public static function definitions(): array
    {
        $initial = BusinessRulesDocument::initial();

        return [
            ...self::pricingRows($initial),
            ...self::holdsRows($initial),
            self::here(
                'cancellation-bands',
                RuleGroup::Cancellation,
                '§4.1.5',
                'Stay cancellation penalty bands',
                RuleStatus::TextInDrafting,
                ['cancellation.bands'],
                BusinessRulesDocument::sourceDisplay('cancellation.bands'),
                data_get($initial, 'cancellation.bands'),
                'Refund Approvals, penalty engine',
            ),
            self::here(
                'cancellation-charter-bands',
                RuleGroup::Cancellation,
                'O6',
                'Charter cancellation penalty bands',
                RuleStatus::PendingClient,
                ['cancellation.charter_bands'],
                BusinessRulesDocument::sourceDisplay('cancellation.charter_bands'),
                data_get($initial, 'cancellation.charter_bands'),
                'Refund Approvals, penalty engine',
            ),
            self::here(
                'cancellation-sets',
                RuleGroup::Cancellation,
                '09 H8',
                'Cancellation band sets',
                RuleStatus::TextInDrafting,
                [
                    'cancellation.sets.STANDARD',
                    'cancellation.sets.CHARTER',
                    'cancellation.sets.standard',
                    'cancellation.sets.non_refundable',
                ],
                BusinessRulesDocument::sourceDisplay('cancellation.sets'),
                [
                    'cancellation.sets.STANDARD' => data_get($initial, 'cancellation.sets.STANDARD'),
                    'cancellation.sets.CHARTER' => data_get($initial, 'cancellation.sets.CHARTER'),
                    'cancellation.sets.standard' => data_get($initial, 'cancellation.sets.standard'),
                    'cancellation.sets.non_refundable' => data_get($initial, 'cancellation.sets.non_refundable'),
                ],
                'Rate plans, penalty engine',
            ),
            self::here(
                'taxes',
                RuleGroup::PricingPayments,
                '09 H9',
                'Taxes and fees',
                RuleStatus::TextInDrafting,
                ['taxes'],
                BusinessRulesDocument::sourceDisplay('taxes'),
                data_get($initial, 'taxes'),
                'Stay quotes',
            ),
            ...self::guestsRows($initial),
            self::here(
                'retention-passport',
                RuleGroup::DataRetention,
                '§6.4',
                'Passport retention after check-out',
                RuleStatus::PendingLegal,
                ['retention.passport_months_after_check_out'],
                BusinessRulesDocument::sourceDisplay('retention.passport_months_after_check_out'),
                data_get($initial, 'retention.passport_months_after_check_out'),
                'Guests tab, retention jobs',
            ),
            self::here(
                'retention-medical',
                RuleGroup::DataRetention,
                'LEG-002',
                'Medical notes retention after check-out',
                RuleStatus::PendingLegal,
                ['retention.medical_days_after_check_out'],
                BusinessRulesDocument::sourceDisplay('retention.medical_days_after_check_out'),
                data_get($initial, 'retention.medical_days_after_check_out'),
                'Guests tab, retention jobs',
            ),
            self::here(
                'retention-behavioural-raw',
                RuleGroup::DataRetention,
                'L6',
                'Behavioural events raw retention',
                RuleStatus::PendingLegal,
                ['retention.behavioural_raw_months'],
                BusinessRulesDocument::sourceDisplay('retention.behavioural_raw_months'),
                data_get($initial, 'retention.behavioural_raw_months'),
                'Web & Engine Activity, events retention job',
            ),
            self::here(
                'retention-behavioural-unstitched',
                RuleGroup::DataRetention,
                'L6',
                'Unstitched anonymous events retention',
                RuleStatus::PendingLegal,
                ['retention.behavioural_unstitched_days'],
                BusinessRulesDocument::sourceDisplay('retention.behavioural_unstitched_days'),
                data_get($initial, 'retention.behavioural_unstitched_days'),
                'Web & Engine Activity, events retention job',
            ),
            self::here(
                'report-retention',
                RuleGroup::DataRetention,
                'O2',
                'Generated report file retention',
                RuleStatus::PendingClient,
                ['reports.retention_days', 'reports.pickup_days'],
                BusinessRulesDocument::sourceDisplay('reports.retention_days'),
                data_get($initial, 'reports.retention_days'),
                'Reports, retention job',
            ),
            ...self::legalRows($initial),
            ...self::crmRows($initial),
            ...self::stayRows($initial),
            ...self::lockedRows(),
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     group: string,
     *     group_label: string,
     *     source_code: string,
     *     name: string,
     *     status: string,
     *     where: string,
     *     paths: list<string>,
     *     source_display: string,
     *     source_value: mixed,
     *     current_display: string,
     *     differs: bool|null,
     *     used_in: string,
     *     lock_reason: string|null,
     *     note: string|null,
     *     link: string|null
     * }>
     */
    public static function rows(CurrentConfig $current): array
    {
        $evaluated = [];

        foreach (self::definitions() as $definition) {
            $snapshot = self::current($definition, $current);

            $evaluated[] = [
                'key' => $definition->key,
                'group' => $definition->group->value,
                'group_label' => $definition->group->label(),
                'source_code' => $definition->sourceCode,
                'name' => $definition->name,
                'status' => $definition->status->value,
                'where' => $definition->where->value,
                'paths' => $definition->paths,
                'source_display' => $definition->sourceDisplay,
                'source_value' => $definition->sourceValue,
                'current_display' => $snapshot['display'],
                'differs' => $snapshot['differs'],
                'used_in' => $definition->usedIn,
                'lock_reason' => $definition->lockReason,
                'note' => $definition->note,
                'link' => $definition->link,
            ];
        }

        return $evaluated;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{all: int, here: int, other_pages: int, locked: int, differs_or_flagged: int}
     */
    public static function counts(array $rows): array
    {
        $other = 0;
        $here = 0;
        $locked = 0;
        $flagged = 0;

        foreach ($rows as $row) {
            $where = $row['where'];

            if ($where === RuleWhere::Here->value) {
                $here++;
            } elseif ($where === RuleWhere::Locked->value) {
                $locked++;
            } elseif (in_array($where, [
                RuleWhere::Rates->value,
                RuleWhere::EngineSettings->value,
            ], true)) {
                $other++;
            }

            $status = RuleStatus::tryFrom((string) $row['status']);
            $pending = $status instanceof RuleStatus && $status->isPending();

            if ($row['differs'] === true || $pending || ($row['note'] ?? null) !== null) {
                $flagged++;
            }
        }

        return [
            'all' => count($rows),
            'here' => $here,
            'other_pages' => $other,
            'locked' => $locked,
            'differs_or_flagged' => $flagged,
        ];
    }

    /**
     * @return list<string>
     */
    public static function herePaths(): array
    {
        $paths = [];

        foreach (self::definitions() as $definition) {
            if ($definition->where !== RuleWhere::Here) {
                continue;
            }

            foreach ($definition->paths as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function current(RuleDefinition $definition, CurrentConfig $current): array
    {
        return match ($definition->where) {
            RuleWhere::Here => self::hereCurrent($definition, $current),
            RuleWhere::Rates => self::ratesCurrent($definition, $current),
            RuleWhere::EngineSettings => self::engineCurrent($definition, $current),
            RuleWhere::Locked => [
                'display' => self::lockedDisplay($definition->key),
                'differs' => null,
            ],
        };
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function hereCurrent(RuleDefinition $definition, CurrentConfig $current): array
    {
        $document = $current->businessRules()->toArray();

        if (count($definition->paths) === 1) {
            $value = data_get($document, $definition->paths[0]);

            return [
                'display' => self::formatHere($definition, $document),
                'differs' => ! DocumentDiff::equal($value, $definition->sourceValue),
            ];
        }

        $values = [];

        foreach ($definition->paths as $path) {
            $values[$path] = data_get($document, $path);
        }

        return [
            'display' => self::formatHere($definition, $document),
            'differs' => ! DocumentDiff::equal($values, $definition->sourceValue),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function formatHere(RuleDefinition $definition, array $document): string
    {
        return match ($definition->key) {
            'fin-005-commission-cap' => data_get($document, 'commission.cap_pct').'%',
            'rms-default-commission' => data_get($document, 'commission.default_pct').'%',
            'commission-payable-days' => data_get($document, 'commission.payable_days_after_check_out').' days',
            'fin-006-modification-fee' => Money::format((int) data_get($document, 'modification_fee_usd')),
            'extras-due-hours' => data_get($document, 'payments.extras_due_hours').' hours',
            'wire-window-hours' => data_get($document, 'payments.wire_window_hours').' hours',
            'balance-reminders' => implode(' / ', data_get($document, 'payments.balance_reminder_days') ?? []).' days',
            'pretrip-days-before' => data_get($document, 'documents.pre_arrival_days_before').' days',
            'voucher-days-before' => data_get($document, 'documents.voucher_days_before').' days',
            'online-deposit-discount' => data_get($document, 'discounts.online_deposit_discount_pct').'%',
            'max-total-discount' => data_get($document, 'discounts.max_total_discount_pct') === null
                ? 'no cap'
                : data_get($document, 'discounts.max_total_discount_pct').'%',
            'web-checkout-hold' => data_get($document, 'holds.web_minutes').' / '.data_get($document, 'holds.web_extension_minutes').' min',
            'hold-near-term' => data_get($document, 'holds.near_term_business_hours').' business hours',
            'hold-long-lead' => data_get($document, 'holds.long_lead_business_days').' business days',
            'hold-business-days' => self::weekdayList(data_get($document, 'holds.business_days') ?? []),
            'hold-business-day-start' => (string) data_get($document, 'holds.business_day_start'),
            'hold-business-day-end' => (string) data_get($document, 'holds.business_day_end'),
            'hold-holidays' => self::holidayList(data_get($document, 'holds.holidays') ?? []),
            'hold-near-term-max-days' => data_get($document, 'holds.near_term_max_days').' days',
            'response-sla' => data_get($document, 'sla.response_hours').' hours',
            'refund-sla' => data_get($document, 'sla.refund_business_days').' business days',
            'agency-approval-sla' => data_get($document, 'sla.agency_approval_business_days').' business days',
            'portal-invite-valid-days' => data_get($document, 'portal.invite_valid_days').' days',
            'low-occupancy-alert' => data_get($document, 'alerts.low_occupancy_pct').'% / '.data_get($document, 'alerts.low_occupancy_days_before').' days / '.data_get($document, 'alerts.low_occupancy_min_consecutive_nights').' nights',
            'nps-survey' => data_get($document, 'nps.survey_hours_after_check_out', data_get($document, 'nps.survey_hours_after_return')).' h · alert < '.data_get($document, 'nps.alert_below').' · review ≥ '.data_get($document, 'nps.review_request_from'),
            'nps-review-url' => (string) data_get($document, 'nps.review_url'),
            'retention-passport' => data_get($document, 'retention.passport_months_after_check_out', data_get($document, 'retention.passport_months_after_'.'cru'.'ise')).' months',
            'retention-medical' => data_get($document, 'retention.medical_days_after_check_out', data_get($document, 'retention.medical_days_after_'.'cru'.'ise')).' days',
            'retention-behavioural-raw' => data_get($document, 'retention.behavioural_raw_months').' months',
            'retention-behavioural-unstitched' => data_get($document, 'retention.behavioural_unstitched_days').' days',
            'report-retention' => data_get($document, 'reports.retention_days').' days',
            'cancellation-charter-bands' => self::bandDisplay(data_get($document, 'cancellation.charter_bands') ?? []),
            'consent-terms' => (string) data_get($document, 'legal.consent_versions.terms'),
            'consent-cancellation' => (string) data_get($document, 'legal.consent_versions.cancellation'),
            'consent-privacy' => (string) data_get($document, 'legal.consent_versions.privacy'),
            'consent-insurance' => (string) data_get($document, 'legal.consent_versions.insurance'),
            'consent-marketing' => (string) data_get($document, 'legal.consent_versions.marketing'),
            'consent-analytics' => (string) data_get($document, 'legal.consent_versions.analytics'),
            'consent-checkout-marketing' => (string) data_get($document, 'legal.consent_versions.checkout_marketing'),
            'legal-entity-name' => (string) data_get($document, 'legal_entity.name'),
            'legal-entity-address' => implode(' · ', data_get($document, 'legal_entity.address_lines') ?? []),
            'legal-entity-email' => (string) data_get($document, 'legal_entity.email'),
            'legal-entity-website' => (string) data_get($document, 'legal_entity.website'),
            'legal-entity-ein' => (string) data_get($document, 'legal_entity.ein'),
            'legal-entity-bank-name' => (string) data_get($document, 'legal_entity.bank.bank_name'),
            'legal-entity-account-name' => (string) data_get($document, 'legal_entity.bank.account_name'),
            'legal-entity-account-number' => (string) data_get($document, 'legal_entity.bank.account_number'),
            'legal-entity-routing' => (string) data_get($document, 'legal_entity.bank.routing'),
            'legal-entity-swift' => (string) data_get($document, 'legal_entity.bank.swift'),
            'cancellation-bands' => self::bandDisplay(data_get($document, 'cancellation.bands') ?? []),
            'cancellation-sets' => implode(', ', array_keys(is_array(data_get($document, 'cancellation.sets')) ? data_get($document, 'cancellation.sets') : [])),
            'taxes' => self::taxDisplay(data_get($document, 'taxes')),
            'crm-segment-high-ltv' => Money::format((int) data_get($document, 'crm.segment_high_ltv')),
            'crm-pipeline-sla-new-lead' => data_get($document, 'crm.pipeline.sla_new_lead_business_hours').' business hours',
            'crm-pipeline-sla-qualifying' => data_get($document, 'crm.pipeline.sla_qualifying_business_days').' business days',
            'crm-pipeline-sla-negotiation' => data_get($document, 'crm.pipeline.sla_negotiation_business_days').' business days',
            'crm-pipeline-probability-new-lead' => data_get($document, 'crm.pipeline.probability_new_lead').'%',
            'crm-pipeline-probability-qualifying' => data_get($document, 'crm.pipeline.probability_qualifying').'%',
            'crm-pipeline-probability-quoted' => data_get($document, 'crm.pipeline.probability_quoted').'%',
            'crm-pipeline-probability-negotiation' => data_get($document, 'crm.pipeline.probability_negotiation').'%',
            'crm-pipeline-probability-deposit' => data_get($document, 'crm.pipeline.probability_deposit_pending').'%',
            'privacy-request-sla' => data_get($document, 'privacy.request_sla_days').' calendar days',
            'crm-segment-mid-ltv' => Money::format((int) data_get($document, 'crm.segment_mid_ltv')),
            'stay-check-in-time' => (string) data_get($document, 'stay.check_in_time'),
            'stay-check-out-time' => (string) data_get($document, 'stay.check_out_time'),
            'stay-no-show-cutoff' => (string) data_get($document, 'stay.no_show_cutoff_time'),
            'stay-min-nights' => ((int) data_get($document, 'stay.min_nights') === 1 ? '1 night' : data_get($document, 'stay.min_nights').' nights'),
            'stay-max-nights' => data_get($document, 'stay.max_nights').' nights',
            'stay-max-rooms' => data_get($document, 'stay.max_rooms_per_booking').' rooms',
            'stay-check-in-full-payment' => data_get($document, 'stay.check_in_requires_full_payment') === true ? 'Yes' : 'No',
            'stay-booking-horizon' => data_get($document, 'stay.booking_horizon_days').' days',
            'guest-registration' => self::registrationDisplay($document),
            default => $definition->sourceDisplay,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function registrationDisplay(array $document): string
    {
        $fields = data_get($document, 'registration.fields');
        $list = is_array($fields) ? implode(', ', $fields) : '';
        $deadline = data_get($document, 'registration.deadline_hours_after_check_in');
        $deadlineText = $deadline === null ? 'no deadline' : $deadline.' h after check-in';

        return $list.' · '.$deadlineText;
    }

    /**
     * @param  list<int|string>  $days
     */
    private static function weekdayList(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $labels = [];

        foreach ($days as $day) {
            $labels[] = $names[(int) $day] ?? (string) $day;
        }

        return implode(', ', $labels);
    }

    /**
     * @param  list<string>  $holidays
     */
    private static function holidayList(array $holidays): string
    {
        return $holidays === [] ? 'none' : implode(', ', $holidays);
    }

    /**
     * @param  list<array{min_days?: int, penalty_pct?: int}>  $bands
     */
    private static function bandDisplay(array $bands): string
    {
        usort($bands, fn (array $a, array $b): int => ((int) ($b['min_days'] ?? 0)) <=> ((int) ($a['min_days'] ?? 0)));

        $parts = [];

        foreach ($bands as $index => $band) {
            $min = (int) ($band['min_days'] ?? 0);
            $pct = (int) ($band['penalty_pct'] ?? 0);
            $range = $index === 0
                ? '≥'.$min.' days'
                : $min.'–'.(((int) ($bands[$index - 1]['min_days'] ?? 0)) - 1).' days';
            $parts[] = $range.' '.$pct.'%';
        }

        return implode(' · ', $parts);
    }

    private static function taxDisplay(mixed $taxes): string
    {
        if (! is_array($taxes) || $taxes === []) {
            return 'none';
        }

        $count = count($taxes);

        return $count === 1 ? '1 tax' : $count.' taxes';
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function ratesCurrent(RuleDefinition $definition, CurrentConfig $current): array
    {
        if (! $current->has(ConfigKind::Rates)) {
            return ['display' => '—', 'differs' => null];
        }

        $rates = $current->rates();

        return match ($definition->key) {
            'fin-001-base-rates', 'fin-001-annual-increase', 'fin-003-charter-deposit' => self::planSummary($rates),
            'single-triple', 'ops-004-child-discount', 'festive-supplement' => self::supplementSummary($rates),
            default => ['display' => '—', 'differs' => null],
        };
    }

    /**
     * @return array{display: string, differs: bool}
     */
    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function planSummary(RatesDocument $rates): array
    {
        $plan = null;

        foreach ($rates->ratePlans as $candidate) {
            if ($candidate->isDefault) {
                $plan = $candidate;
                break;
            }
        }

        $plan ??= $rates->ratePlans[0] ?? null;

        if ($plan === null) {
            return ['display' => '—', 'differs' => null];
        }

        return [
            'display' => $plan->name.' · deposit '.$plan->depositPct.'% · balance '.$plan->balanceDays.' days',
            'differs' => null,
        ];
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function supplementSummary(RatesDocument $rates): array
    {
        return [
            'display' => count($rates->supplements).' supplements',
            'differs' => null,
        ];
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function engineCurrent(RuleDefinition $definition, CurrentConfig $current): array
    {
        if (! $current->has(ConfigKind::EngineSettings)) {
            return ['display' => '—', 'differs' => null];
        }

        $engine = $current->engineSettings();

        return match ($definition->key) {
            'ops-004-child-age' => [
                'display' => $engine->guests->childMinAge.' years',
                'differs' => $engine->guests->childMinAge !== 6,
            ],
            'ops-002-guests-per-property' => [
                'display' => $engine->guests->maxPerProperty.' guests',
                'differs' => $engine->guests->maxPerProperty !== 16,
            ],
            'fin-004-galapagos-fees' => self::fin004($current),
            'language' => [
                'display' => 'English only',
                'differs' => ! DocumentDiff::equal(
                    $engine->locale->toArray(),
                    ['default' => 'en', 'live' => ['en'], 'currency' => 'USD'],
                ),
            ],
            default => ['display' => '—', 'differs' => null],
        };
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    private static function fin004(CurrentConfig $current): array
    {
        if (! $current->has(ConfigKind::BusinessRules)) {
            return ['display' => '—', 'differs' => null];
        }

        return [
            'display' => self::taxDisplay($current->businessRules()->taxes),
            'differs' => null,
        ];
    }

    private static function lockedDisplay(string $key): string
    {
        return match ($key) {
            'ops-003-home-port' => 'San Cristóbal (SCY)',
            'ops-005-travel-insurance' => "Passenger's responsibility — declaration mandatory at step 5",
            'ops-007-overdue' => 'Alert the team — never auto-cancel',
            'ops-008-fit-groups' => 'Same rates, same process · coordinator only',
            'r-b5-waitlist' => 'First in, first out',
            'offers-festive' => 'Never',
            'never-overbook' => 'Never — the last room on hold shows Limited Availability',
            'availability-sla' => 'Under 30 s after any RMS change',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function pricingRows(array $initial): array
    {
        $g = RuleGroup::PricingPayments;

        return [
            new RuleDefinition(
                'fin-001-base-rates',
                $g,
                'FIN-001',
                "Base rates 2027 — Suite / Owner's / Charter",
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                'USD 13,300 · 25,000 · 199,500',
                null,
                'Engine prices, quotes, feed',
                link: self::LINK_RATES,
            ),
            new RuleDefinition(
                'fin-001-annual-increase',
                $g,
                'FIN-001',
                'Annual increase',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '+5% per year',
                null,
                'Rate helper, future years',
                link: self::LINK_RATES,
            ),
            new RuleDefinition(
                'fin-003-charter-deposit',
                $g,
                'FIN-003',
                'Charter deposit / balance',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '20% within 5 business days · 80% at T−120',
                null,
                'Charter quotes, Payments',
                link: self::LINK_RATES,
            ),
            new RuleDefinition(
                'single-triple',
                $g,
                '§3.4.1',
                'Single supplement / triple discount',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '+75% · −10% × 3',
                null,
                'Engine price panel, quotes',
                link: self::LINK_RATES,
            ),
            new RuleDefinition(
                'ops-004-child-discount',
                $g,
                'OPS-004',
                'Child discount',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '−15% · max 1/adult, 2/couple',
                null,
                'Engine price panel, quotes',
                link: self::LINK_RATES,
            ),
            new RuleDefinition(
                'festive-supplement',
                $g,
                '§3.4.1',
                'Festive supplement',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '+USD 750 / PAX · +USD 12,000 / charter',
                null,
                'Engine, quotes',
                link: self::LINK_RATES,
            ),
            self::here(
                'fin-005-commission-cap',
                $g,
                'FIN-005',
                'Max agency commission (above → Director approval)',
                RuleStatus::Confirmed,
                ['commission.cap_pct'],
                BusinessRulesDocument::sourceDisplay('commission.cap_pct'),
                data_get($initial, 'commission.cap_pct'),
                'New reservation, Offers, Payments, B2B',
            ),
            self::here(
                'rms-default-commission',
                $g,
                'RMS',
                'Default agency commission',
                RuleStatus::Confirmed,
                ['commission.default_pct'],
                BusinessRulesDocument::sourceDisplay('commission.default_pct'),
                data_get($initial, 'commission.default_pct'),
                'New reservation form, B2B offers',
            ),
            self::here(
                'commission-payable-days',
                $g,
                '§10',
                'Commission payable after check-out',
                RuleStatus::Confirmed,
                ['commission.payable_days_after_check_out'],
                BusinessRulesDocument::sourceDisplay('commission.payable_days_after_check_out'),
                data_get($initial, 'commission.payable_days_after_check_out'),
                'Payments, commissions',
            ),
            self::here(
                'fin-006-modification-fee',
                $g,
                'FIN-006',
                'Date-change / modification fee',
                RuleStatus::Confirmed,
                ['modification_fee_usd'],
                BusinessRulesDocument::sourceDisplay('modification_fee_usd'),
                data_get($initial, 'modification_fee_usd'),
                'Booking drawer (change dates)',
            ),
            self::here(
                'extras-due-hours',
                $g,
                'Iconic',
                'Extras and collected fees — due before check-in',
                RuleStatus::Confirmed,
                ['payments.extras_due_hours'],
                BusinessRulesDocument::sourceDisplay('payments.extras_due_hours'),
                data_get($initial, 'payments.extras_due_hours'),
                'Invoice payment schedule, Extras tab',
            ),
            self::here(
                'wire-window-hours',
                $g,
                'RMS',
                'Wire transfer window before auto-release',
                RuleStatus::Confirmed,
                ['payments.wire_window_hours'],
                BusinessRulesDocument::sourceDisplay('payments.wire_window_hours'),
                data_get($initial, 'payments.wire_window_hours'),
                'Pending-payment bookings',
            ),
            self::here(
                'balance-reminders',
                $g,
                '§4.1.4',
                'Balance reminders — days before due',
                RuleStatus::Confirmed,
                ['payments.balance_reminder_days'],
                BusinessRulesDocument::sourceDisplay('payments.balance_reminder_days'),
                data_get($initial, 'payments.balance_reminder_days'),
                'Payment reminder automation',
            ),
            self::here(
                'pretrip-days-before',
                $g,
                'J7',
                'Pre-arrival information — days before check-in',
                RuleStatus::Confirmed,
                ['documents.pre_arrival_days_before'],
                BusinessRulesDocument::sourceDisplay('documents.pre_arrival_days_before'),
                data_get($initial, 'documents.pre_arrival_days_before'),
                'Document schedule',
            ),
            self::here(
                'voucher-days-before',
                $g,
                'J7',
                'Transfer voucher — days before check-in',
                RuleStatus::Confirmed,
                ['documents.voucher_days_before'],
                BusinessRulesDocument::sourceDisplay('documents.voucher_days_before'),
                data_get($initial, 'documents.voucher_days_before'),
                'Document schedule',
            ),
            self::here(
                'online-deposit-discount',
                $g,
                '08 B2',
                'Online-deposit advantage',
                RuleStatus::PendingClient,
                ['discounts.online_deposit_discount_pct'],
                BusinessRulesDocument::sourceDisplay('discounts.online_deposit_discount_pct'),
                data_get($initial, 'discounts.online_deposit_discount_pct'),
                'Pricing engine, engine checkout',
            ),
            self::here(
                'max-total-discount',
                $g,
                '08 B2',
                'Max total discount',
                RuleStatus::PendingClient,
                ['discounts.max_total_discount_pct'],
                BusinessRulesDocument::sourceDisplay('discounts.max_total_discount_pct'),
                data_get($initial, 'discounts.max_total_discount_pct'),
                'Pricing engine, offers',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function holdsRows(array $initial): array
    {
        $g = RuleGroup::HoldsServiceLevels;

        return [
            self::here(
                'web-checkout-hold',
                $g,
                'R-B2',
                'Web checkout hold (+ one silent extension)',
                RuleStatus::Confirmed,
                ['holds.web_minutes', 'holds.web_extension_minutes'],
                BusinessRulesDocument::sourceDisplay('holds.web_minutes'),
                [
                    'holds.web_minutes' => data_get($initial, 'holds.web_minutes'),
                    'holds.web_extension_minutes' => data_get($initial, 'holds.web_extension_minutes'),
                ],
                'Holds & Waitlist, engine checkout',
            ),
            self::here(
                'hold-near-term',
                $g,
                'TEC-004',
                'Request / agency hold — near-term',
                RuleStatus::Confirmed,
                ['holds.near_term_business_hours'],
                BusinessRulesDocument::sourceDisplay('holds.near_term_business_hours'),
                data_get($initial, 'holds.near_term_business_hours'),
                'Booking Requests, Holds',
            ),
            self::here(
                'hold-long-lead',
                $g,
                'TEC-004',
                'Request / agency hold — long-lead',
                RuleStatus::Confirmed,
                ['holds.long_lead_business_days'],
                BusinessRulesDocument::sourceDisplay('holds.long_lead_business_days'),
                data_get($initial, 'holds.long_lead_business_days'),
                'Booking Requests, Holds, charter quotes (OPS-010)',
            ),
            self::here(
                'hold-business-days',
                $g,
                'TEC-004',
                'Business days',
                RuleStatus::PendingClient,
                ['holds.business_days'],
                BusinessRulesDocument::sourceDisplay('holds.business_days'),
                data_get($initial, 'holds.business_days'),
                'Booking Requests, Holds',
            ),
            self::here(
                'hold-business-day-start',
                $g,
                'TEC-004',
                'Business day start',
                RuleStatus::PendingClient,
                ['holds.business_day_start'],
                BusinessRulesDocument::sourceDisplay('holds.business_day_start'),
                data_get($initial, 'holds.business_day_start'),
                'Booking Requests, Holds',
            ),
            self::here(
                'hold-business-day-end',
                $g,
                'TEC-004',
                'Business day end',
                RuleStatus::PendingClient,
                ['holds.business_day_end'],
                BusinessRulesDocument::sourceDisplay('holds.business_day_end'),
                data_get($initial, 'holds.business_day_end'),
                'Booking Requests, Holds',
            ),
            self::here(
                'hold-holidays',
                $g,
                'TEC-004',
                'Holidays',
                RuleStatus::PendingClient,
                ['holds.holidays'],
                BusinessRulesDocument::sourceDisplay('holds.holidays'),
                data_get($initial, 'holds.holidays'),
                'Booking Requests, Holds',
            ),
            self::here(
                'hold-near-term-max-days',
                $g,
                'TEC-004',
                'Near-term window',
                RuleStatus::PendingClient,
                ['holds.near_term_max_days'],
                BusinessRulesDocument::sourceDisplay('holds.near_term_max_days'),
                data_get($initial, 'holds.near_term_max_days'),
                'Booking Requests, Holds',
            ),
            self::here(
                'response-sla',
                $g,
                'OPS-009',
                'Quote / first-response SLA (FIT, groups, charter)',
                RuleStatus::Confirmed,
                ['sla.response_hours'],
                BusinessRulesDocument::sourceDisplay('sla.response_hours'),
                data_get($initial, 'sla.response_hours'),
                'Booking Requests, engine confirmation copy',
            ),
            self::here(
                'refund-sla',
                $g,
                'RMS',
                'Refund execution SLA',
                RuleStatus::Confirmed,
                ['sla.refund_business_days'],
                BusinessRulesDocument::sourceDisplay('sla.refund_business_days'),
                data_get($initial, 'sla.refund_business_days'),
                'Refund Approvals',
            ),
            self::here(
                'agency-approval-sla',
                $g,
                '§5.5',
                'Agency approval SLA',
                RuleStatus::Confirmed,
                ['sla.agency_approval_business_days'],
                BusinessRulesDocument::sourceDisplay('sla.agency_approval_business_days'),
                data_get($initial, 'sla.agency_approval_business_days'),
                'B2B & Agent Portal',
            ),
            self::here(
                'portal-invite-valid-days',
                $g,
                '§5.5',
                'Portal invitation validity',
                RuleStatus::PendingClient,
                ['portal.invite_valid_days'],
                BusinessRulesDocument::sourceDisplay('portal.invite_valid_days'),
                data_get($initial, 'portal.invite_valid_days'),
                'Agent portal invitations',
            ),
            self::here(
                'low-occupancy-alert',
                $g,
                '§10',
                'Low-occupancy alert',
                RuleStatus::Confirmed,
                ['alerts.low_occupancy_pct', 'alerts.low_occupancy_days_before', 'alerts.low_occupancy_min_consecutive_nights'],
                BusinessRulesDocument::sourceDisplay('alerts.low_occupancy_pct'),
                [
                    'alerts.low_occupancy_pct' => data_get($initial, 'alerts.low_occupancy_pct'),
                    'alerts.low_occupancy_days_before' => data_get($initial, 'alerts.low_occupancy_days_before'),
                    'alerts.low_occupancy_min_consecutive_nights' => data_get($initial, 'alerts.low_occupancy_min_consecutive_nights'),
                ],
                'Occupancy alerts',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function guestsRows(array $initial): array
    {
        $g = RuleGroup::GuestsCapacity;

        return [
            self::here(
                'guest-registration',
                $g,
                'HQ9',
                'Guest registration fields',
                RuleStatus::PendingClient,
                [
                    'registration.fields',
                    'registration.formats',
                    'registration.deadline_hours_after_check_in',
                ],
                BusinessRulesDocument::sourceDisplay('registration.fields'),
                [
                    'registration.fields' => data_get($initial, 'registration.fields'),
                    'registration.formats' => data_get($initial, 'registration.formats'),
                    'registration.deadline_hours_after_check_in' => data_get($initial, 'registration.deadline_hours_after_check_in'),
                ],
                'Front desk registration export',
            ),
            new RuleDefinition(
                'ops-004-child-age',
                $g,
                'OPS-004',
                'Minimum child age (at check-in)',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                '6 years',
                null,
                'Engine guest picker, New reservation',
                link: self::LINK_ENGINE,
            ),
            new RuleDefinition(
                'ops-002-guests-per-property',
                $g,
                'OPS-002',
                'Guests per property',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                '16 PAX',
                null,
                'Engine, charter capacity',
                link: self::LINK_ENGINE,
            ),
            new RuleDefinition(
                'fin-004-galapagos-fees',
                $g,
                'FIN-004',
                'Taxes and fees',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                'Empty until published (09 H9)',
                null,
                'Invoice fees section, Guests tab',
                link: self::LINK_ENGINE,
            ),
            new RuleDefinition(
                'language',
                $g,
                'Iconic',
                'Language',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                'English only',
                null,
                'Engine, documents, panel',
                link: self::LINK_ENGINE,
            ),
            new RuleDefinition(
                'ops-006-sales-open',
                $g,
                'OPS-006',
                'Sales open',
                RuleStatus::Confirmed,
                RuleWhere::Locked,
                [],
                'Sales open 1 Nov 2026',
                null,
                'Engine calendar',
                note: 'Iconic 12 Sep 2026: keep OPS-006 — sales open 1 Nov 2026.',
                link: self::LINK_ENGINE,
            ),
            self::here(
                'nps-survey',
                $g,
                'N8',
                'Post-trip survey — delay, alert and review thresholds',
                RuleStatus::Confirmed,
                ['nps.survey_hours_after_check_out', 'nps.alert_below', 'nps.review_request_from'],
                BusinessRulesDocument::sourceDisplay('nps.survey_hours_after_check_out'),
                [
                    'nps.survey_hours_after_check_out' => data_get(BusinessRulesDocument::initial(), 'nps.survey_hours_after_check_out'),
                    'nps.alert_below' => data_get(BusinessRulesDocument::initial(), 'nps.alert_below'),
                    'nps.review_request_from' => data_get(BusinessRulesDocument::initial(), 'nps.review_request_from'),
                ],
                'Guest Experience',
            ),
            self::here(
                'nps-review-url',
                $g,
                'LEG-002',
                'Public review URL',
                RuleStatus::PendingClient,
                ['nps.review_url'],
                BusinessRulesDocument::sourceDisplay('nps.review_url'),
                data_get(BusinessRulesDocument::initial(), 'nps.review_url'),
                'Guest Experience',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function legalRows(array $initial): array
    {
        $group = RuleGroup::Legal;

        return [
            self::here(
                'legal-entity-name',
                $group,
                'Decision 8',
                'Invoicing entity',
                RuleStatus::Confirmed,
                ['legal_entity.name'],
                BusinessRulesDocument::sourceDisplay('legal_entity.name'),
                data_get($initial, 'legal_entity.name'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-address',
                $group,
                'Decision 8',
                'Invoicing entity address',
                RuleStatus::Confirmed,
                ['legal_entity.address_lines'],
                BusinessRulesDocument::sourceDisplay('legal_entity.address_lines'),
                data_get($initial, 'legal_entity.address_lines'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-email',
                $group,
                'Decision 8',
                'Invoicing entity email',
                RuleStatus::Confirmed,
                ['legal_entity.email'],
                BusinessRulesDocument::sourceDisplay('legal_entity.email'),
                data_get($initial, 'legal_entity.email'),
                'Invoices, documents footer',
            ),
            self::here(
                'legal-entity-website',
                $group,
                'Decision 8',
                'Invoicing entity website',
                RuleStatus::Confirmed,
                ['legal_entity.website'],
                BusinessRulesDocument::sourceDisplay('legal_entity.website'),
                data_get($initial, 'legal_entity.website'),
                'Invoices, documents footer',
            ),
            self::here(
                'legal-entity-ein',
                $group,
                'Decision 8',
                'EIN',
                RuleStatus::Confirmed,
                ['legal_entity.ein'],
                BusinessRulesDocument::sourceDisplay('legal_entity.ein'),
                data_get($initial, 'legal_entity.ein'),
                'Invoices',
            ),
            self::here(
                'legal-entity-bank-name',
                $group,
                'LEG-004',
                'Bank name',
                RuleStatus::PendingClient,
                ['legal_entity.bank.bank_name'],
                BusinessRulesDocument::sourceDisplay('legal_entity.bank.bank_name'),
                data_get($initial, 'legal_entity.bank.bank_name'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-account-name',
                $group,
                'LEG-004',
                'Account name',
                RuleStatus::PendingClient,
                ['legal_entity.bank.account_name'],
                BusinessRulesDocument::sourceDisplay('legal_entity.bank.account_name'),
                data_get($initial, 'legal_entity.bank.account_name'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-account-number',
                $group,
                'LEG-004',
                'Account number',
                RuleStatus::PendingClient,
                ['legal_entity.bank.account_number'],
                BusinessRulesDocument::sourceDisplay('legal_entity.bank.account_number'),
                data_get($initial, 'legal_entity.bank.account_number'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-routing',
                $group,
                'LEG-004',
                'Routing number',
                RuleStatus::PendingClient,
                ['legal_entity.bank.routing'],
                BusinessRulesDocument::sourceDisplay('legal_entity.bank.routing'),
                data_get($initial, 'legal_entity.bank.routing'),
                'Invoices, wire instructions',
            ),
            self::here(
                'legal-entity-swift',
                $group,
                'LEG-004',
                'SWIFT',
                RuleStatus::PendingClient,
                ['legal_entity.bank.swift'],
                BusinessRulesDocument::sourceDisplay('legal_entity.bank.swift'),
                data_get($initial, 'legal_entity.bank.swift'),
                'Invoices, wire instructions',
            ),
            self::here(
                'consent-terms',
                $group,
                'LEG-001',
                'Terms & Conditions version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.terms'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.terms'),
                data_get($initial, 'legal.consent_versions.terms'),
                'Guests tab, consent log',
            ),
            self::here(
                'consent-cancellation',
                $group,
                'LEG-001',
                'Cancellation policy version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.cancellation'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.cancellation'),
                data_get($initial, 'legal.consent_versions.cancellation'),
                'Guests tab, consent log',
            ),
            self::here(
                'consent-privacy',
                $group,
                'LEG-002',
                'Privacy policy version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.privacy'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.privacy'),
                data_get($initial, 'legal.consent_versions.privacy'),
                'Guests tab, consent log',
            ),
            self::here(
                'consent-insurance',
                $group,
                'OPS-005',
                'Travel insurance declaration version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.insurance'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.insurance'),
                data_get($initial, 'legal.consent_versions.insurance'),
                'Guests tab, consent log',
            ),
            self::here(
                'consent-marketing',
                $group,
                'LEG-002',
                'Marketing consent version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.marketing'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.marketing'),
                data_get($initial, 'legal.consent_versions.marketing'),
                'Guests tab, consent log',
            ),
            self::here(
                'consent-analytics',
                $group,
                'LEG-002',
                'Analytics consent version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.analytics'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.analytics'),
                data_get($initial, 'legal.consent_versions.analytics'),
                'CRM consent register',
            ),
            self::here(
                'consent-checkout-marketing',
                $group,
                'LEG-002',
                'Checkout marketing consent version',
                RuleStatus::PendingClient,
                ['legal.consent_versions.checkout_marketing'],
                BusinessRulesDocument::sourceDisplay('legal.consent_versions.checkout_marketing'),
                data_get($initial, 'legal.consent_versions.checkout_marketing'),
                'Engine checkout marketing tick',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function crmRows(array $initial): array
    {
        $g = RuleGroup::Crm;

        return [
            self::here(
                'crm-segment-high-ltv',
                $g,
                'L2',
                'CRM segment HIGH — lifetime-value threshold',
                RuleStatus::PendingClient,
                ['crm.segment_high_ltv'],
                BusinessRulesDocument::sourceDisplay('crm.segment_high_ltv'),
                data_get($initial, 'crm.segment_high_ltv'),
                'CRM Contacts list and profile',
            ),
            self::here(
                'crm-segment-mid-ltv',
                $g,
                'L2',
                'CRM segment MID — lifetime-value threshold',
                RuleStatus::PendingClient,
                ['crm.segment_mid_ltv'],
                BusinessRulesDocument::sourceDisplay('crm.segment_mid_ltv'),
                data_get($initial, 'crm.segment_mid_ltv'),
                'CRM Contacts list and profile',
            ),
            self::here(
                'crm-pipeline-sla-new-lead',
                $g,
                'M4',
                'New lead SLA',
                RuleStatus::PendingClient,
                ['crm.pipeline.sla_new_lead_business_hours'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.sla_new_lead_business_hours'),
                data_get($initial, 'crm.pipeline.sla_new_lead_business_hours'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-sla-qualifying',
                $g,
                'M4',
                'Qualifying SLA',
                RuleStatus::PendingClient,
                ['crm.pipeline.sla_qualifying_business_days'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.sla_qualifying_business_days'),
                data_get($initial, 'crm.pipeline.sla_qualifying_business_days'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-sla-negotiation',
                $g,
                'M4',
                'Negotiation SLA',
                RuleStatus::PendingClient,
                ['crm.pipeline.sla_negotiation_business_days'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.sla_negotiation_business_days'),
                data_get($initial, 'crm.pipeline.sla_negotiation_business_days'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-probability-new-lead',
                $g,
                'M4',
                'New lead probability',
                RuleStatus::PendingClient,
                ['crm.pipeline.probability_new_lead'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.probability_new_lead'),
                data_get($initial, 'crm.pipeline.probability_new_lead'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-probability-qualifying',
                $g,
                'M4',
                'Qualifying probability',
                RuleStatus::PendingClient,
                ['crm.pipeline.probability_qualifying'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.probability_qualifying'),
                data_get($initial, 'crm.pipeline.probability_qualifying'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-probability-quoted',
                $g,
                'M4',
                'Quoted probability',
                RuleStatus::PendingClient,
                ['crm.pipeline.probability_quoted'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.probability_quoted'),
                data_get($initial, 'crm.pipeline.probability_quoted'),
                'CRM pipeline',
            ),
            self::here(
                'crm-pipeline-probability-negotiation',
                $g,
                'M4',
                'Negotiation probability',
                RuleStatus::PendingClient,
                ['crm.pipeline.probability_negotiation'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.probability_negotiation'),
                data_get($initial, 'crm.pipeline.probability_negotiation'),
                'CRM pipeline',
            ),
            self::here(
                'privacy-request-sla',
                $g,
                'M7',
                'Subject request SLA',
                RuleStatus::PendingClient,
                ['privacy.request_sla_days'],
                BusinessRulesDocument::sourceDisplay('privacy.request_sla_days'),
                data_get($initial, 'privacy.request_sla_days'),
                'Subject requests',
            ),
            self::here(
                'crm-pipeline-probability-deposit',
                $g,
                'M4',
                'Deposit pending probability',
                RuleStatus::PendingClient,
                ['crm.pipeline.probability_deposit_pending'],
                BusinessRulesDocument::sourceDisplay('crm.pipeline.probability_deposit_pending'),
                data_get($initial, 'crm.pipeline.probability_deposit_pending'),
                'CRM pipeline',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $initial
     * @return list<RuleDefinition>
     */
    private static function stayRows(array $initial): array
    {
        $g = RuleGroup::Stay;

        return [
            self::here(
                'stay-check-in-time',
                $g,
                'HQ3',
                'Check-in time',
                RuleStatus::PendingClient,
                ['stay.check_in_time'],
                BusinessRulesDocument::sourceDisplay('stay.check_in_time'),
                data_get($initial, 'stay.check_in_time'),
                'Stay clock',
            ),
            self::here(
                'stay-check-out-time',
                $g,
                'HQ3',
                'Check-out time',
                RuleStatus::PendingClient,
                ['stay.check_out_time'],
                BusinessRulesDocument::sourceDisplay('stay.check_out_time'),
                data_get($initial, 'stay.check_out_time'),
                'Stay clock',
            ),
            self::here(
                'stay-no-show-cutoff',
                $g,
                'HQ3',
                'No-show cutoff',
                RuleStatus::PendingClient,
                ['stay.no_show_cutoff_time'],
                BusinessRulesDocument::sourceDisplay('stay.no_show_cutoff_time'),
                data_get($initial, 'stay.no_show_cutoff_time'),
                'Stay clock',
            ),
            self::here(
                'stay-min-nights',
                $g,
                'HQ3',
                'Minimum nights',
                RuleStatus::PendingClient,
                ['stay.min_nights'],
                BusinessRulesDocument::sourceDisplay('stay.min_nights'),
                data_get($initial, 'stay.min_nights'),
                'Bookings',
            ),
            self::here(
                'stay-max-nights',
                $g,
                'HQ3',
                'Maximum nights',
                RuleStatus::PendingClient,
                ['stay.max_nights'],
                BusinessRulesDocument::sourceDisplay('stay.max_nights'),
                data_get($initial, 'stay.max_nights'),
                'Stay clock',
            ),
            self::here(
                'stay-max-rooms',
                $g,
                'HQ3',
                'Maximum rooms per booking',
                RuleStatus::PendingClient,
                ['stay.max_rooms_per_booking'],
                BusinessRulesDocument::sourceDisplay('stay.max_rooms_per_booking'),
                data_get($initial, 'stay.max_rooms_per_booking'),
                'Bookings',
            ),
            self::here(
                'stay-check-in-full-payment',
                $g,
                'HQ3',
                'Check-in requires full payment',
                RuleStatus::PendingClient,
                ['stay.check_in_requires_full_payment'],
                BusinessRulesDocument::sourceDisplay('stay.check_in_requires_full_payment'),
                data_get($initial, 'stay.check_in_requires_full_payment'),
                'Check-in',
            ),
            self::here(
                'stay-booking-horizon',
                $g,
                'HQ3',
                'Booking horizon',
                RuleStatus::PendingClient,
                ['stay.booking_horizon_days'],
                BusinessRulesDocument::sourceDisplay('stay.booking_horizon_days'),
                data_get($initial, 'stay.booking_horizon_days'),
                'Bookings',
            ),
        ];
    }

    /**
     * @return list<RuleDefinition>
     */
    private static function lockedRows(): array
    {
        $g = RuleGroup::StructuralLocked;

        return [
            self::locked(
                'ops-003-home-port',
                $g,
                'OPS-003',
                'Home port',
                'SCY',
                'Home port is a property fact, not a tunable setting.',
                'Properties, engine',
            ),
            self::locked(
                'ops-005-travel-insurance',
                $g,
                'OPS-005',
                'Travel insurance declaration',
                "Passenger's responsibility; declaration mandatory at step 5",
                "Passenger's responsibility — the declaration is mandatory at booking step 5 and is not a tunable setting.",
                'Engine checkout, booking documents',
            ),
            self::locked(
                'ops-007-overdue',
                $g,
                'OPS-007',
                'Overdue balance',
                'No auto-cancel; decision logged in CRM',
                'CEO decision — switching to auto-cancel needs a new automation and legal review.',
                'Bookings, Payments',
            ),
            self::locked(
                'ops-008-fit-groups',
                $g,
                'OPS-008',
                'FIT vs groups',
                'Same conditions',
                'Process decision.',
                'Bookings, quotes',
            ),
            new RuleDefinition(
                'r-b5-waitlist',
                $g,
                'R-B5',
                'Waitlist order',
                RuleStatus::RmsSpec,
                RuleWhere::Locked,
                [],
                'not in v5',
                null,
                'Holds & Waitlist',
                lockReason: 'Fairness rule — FIFO by request time.',
            ),
            self::locked(
                'offers-festive',
                $g,
                'Offers',
                'Offers on festive dates',
                'Festive blocks all discounts',
                'Follows the festive rule in §3.4.1.',
                'Offers, engine',
            ),
            self::locked(
                'never-overbook',
                $g,
                '§4.4',
                'Never overbook',
                'Never',
                'Inventory rule — the last room on hold shows Limited Availability; the system never sells past physical capacity.',
                'Calendar, engine, holds',
            ),
            self::locked(
                'availability-sla',
                $g,
                '§10',
                'Availability to the site',
                'Under 30 s',
                'Feed freshness is an engineering constraint, not an editable SLA — the engine consumes what the RMS publishes.',
                'Engine feed',
            ),
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private static function here(
        string $key,
        RuleGroup $group,
        string $sourceCode,
        string $name,
        RuleStatus $status,
        array $paths,
        string $sourceDisplay,
        mixed $sourceValue,
        string $usedIn,
        ?string $note = null,
    ): RuleDefinition {
        return new RuleDefinition(
            $key,
            $group,
            $sourceCode,
            $name,
            $status,
            RuleWhere::Here,
            $paths,
            $sourceDisplay,
            $sourceValue,
            $usedIn,
            note: $note,
        );
    }

    private static function locked(
        string $key,
        RuleGroup $group,
        string $sourceCode,
        string $name,
        string $sourceDisplay,
        string $lockReason,
        string $usedIn,
        ?string $note = null,
    ): RuleDefinition {
        return new RuleDefinition(
            $key,
            $group,
            $sourceCode,
            $name,
            RuleStatus::Confirmed,
            RuleWhere::Locked,
            [],
            $sourceDisplay,
            null,
            $usedIn,
            lockReason: $lockReason,
            note: $note,
        );
    }
}
