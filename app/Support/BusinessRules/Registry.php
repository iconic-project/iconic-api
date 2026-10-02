<?php

declare(strict_types=1);

namespace App\Support\BusinessRules;

use App\Enums\ConfigKind;
use App\Enums\RuleGroup;
use App\Enums\RuleStatus;
use App\Enums\RuleWhere;
use App\Services\Config\CurrentConfig;
use App\Services\Config\DepartureConfigChecks;
use App\Support\Config\DocumentDiff;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Money;

final class Registry
{
    private const LINK_RATES = '/rms/commercial/rates';

    private const LINK_ENGINE = '/rms/booking-engine/settings';

    private const LINK_DEPARTURES = '/rms/booking-engine/departures';

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
                'Cabin cancellation penalty bands',
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
                'charter-deposit-business-days',
                RuleGroup::PricingPayments,
                'FIN-003',
                'Charter deposit due',
                RuleStatus::Confirmed,
                ['charter.deposit_business_days'],
                BusinessRulesDocument::sourceDisplay('charter.deposit_business_days'),
                data_get($initial, 'charter.deposit_business_days'),
                'Charter acceptance, deposit clock',
            ),
            self::here(
                'charter-proposal-valid-days',
                RuleGroup::PricingPayments,
                'O5',
                'Charter proposal validity',
                RuleStatus::PendingClient,
                ['charter.proposal_valid_business_days'],
                BusinessRulesDocument::sourceDisplay('charter.proposal_valid_business_days'),
                data_get($initial, 'charter.proposal_valid_business_days'),
                'Charter proposal link',
            ),
            ...self::guestsRows(),
            self::here(
                'retention-passport',
                RuleGroup::DataRetention,
                '§6.4',
                'Passport retention after cruise',
                RuleStatus::PendingLegal,
                ['retention.passport_months_after_cruise'],
                BusinessRulesDocument::sourceDisplay('retention.passport_months_after_cruise'),
                data_get($initial, 'retention.passport_months_after_cruise'),
                'Guests tab, retention jobs',
            ),
            self::here(
                'retention-medical',
                RuleGroup::DataRetention,
                'LEG-002',
                'Medical notes retention after disembarkation',
                RuleStatus::PendingLegal,
                ['retention.medical_days_after_cruise'],
                BusinessRulesDocument::sourceDisplay('retention.medical_days_after_cruise'),
                data_get($initial, 'retention.medical_days_after_cruise'),
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
                ['reports.retention_days'],
                BusinessRulesDocument::sourceDisplay('reports.retention_days'),
                data_get($initial, 'reports.retention_days'),
                'Reports, retention job',
            ),
            ...self::legalRows($initial),
            ...self::crmRows($initial),
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
                RuleWhere::Departures->value,
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
            RuleWhere::Departures => app(DepartureConfigChecks::class)->ops006Current(),
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
            'commission-payable-days' => data_get($document, 'commission.payable_days_after_cruise').' days',
            'fin-006-modification-fee' => Money::format((int) data_get($document, 'modification_fee_usd')),
            'extras-due-hours' => data_get($document, 'payments.extras_due_hours').' hours',
            'wire-window-hours' => data_get($document, 'payments.wire_window_hours').' hours',
            'balance-reminders' => implode(' / ', data_get($document, 'payments.balance_reminder_days') ?? []).' days',
            'pretrip-days-before' => data_get($document, 'documents.pretrip_days_before').' days',
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
            'dpng-manifest' => data_get($document, 'manifests.dpng_fit_days').' / '.data_get($document, 'manifests.dpng_charter_days').' days',
            'captain-manifest' => data_get($document, 'manifests.captain_days').' days',
            'manifest-chase' => data_get($document, 'manifests.chase_days_before_due').' days',
            'low-occupancy-alert' => data_get($document, 'alerts.low_occupancy_pct').'% / '.data_get($document, 'alerts.low_occupancy_days_before').' days',
            'nps-survey' => data_get($document, 'nps.survey_hours_after_return').' h · alert < '.data_get($document, 'nps.alert_below').' · review ≥ '.data_get($document, 'nps.review_request_from'),
            'nps-review-url' => (string) data_get($document, 'nps.review_url'),
            'retention-passport' => data_get($document, 'retention.passport_months_after_cruise').' months',
            'retention-medical' => data_get($document, 'retention.medical_days_after_cruise').' days',
            'retention-behavioural-raw' => data_get($document, 'retention.behavioural_raw_months').' months',
            'retention-behavioural-unstitched' => data_get($document, 'retention.behavioural_unstitched_days').' days',
            'report-retention' => data_get($document, 'reports.retention_days').' days',
            'cancellation-charter-bands' => self::bandDisplay(data_get($document, 'cancellation.charter_bands') ?? []),
            'charter-deposit-business-days' => data_get($document, 'charter.deposit_business_days').' business days',
            'charter-proposal-valid-days' => data_get($document, 'charter.proposal_valid_business_days').' business days',
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
            default => $definition->sourceDisplay,
        };
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
            'fin-001-base-rates' => self::fin001Base($rates),
            'fin-001-annual-increase' => self::fin001Annual($rates),
            'fin-002-cabin-deposit' => [
                'display' => $rates->terms->cabinDepositPct.'% · balance T−'.$rates->terms->cabinBalanceDays,
                'differs' => ! ($rates->terms->cabinDepositPct === 10 && $rates->terms->cabinBalanceDays === 120),
            ],
            'fin-003-charter-deposit' => [
                'display' => $rates->terms->charterDepositPct.'% within '.$rates->terms->charterDepositBusinessDays.' business days · T−'.$rates->terms->charterBalanceDays,
                'differs' => ! ($rates->terms->charterDepositPct === 20
                    && $rates->terms->charterDepositBusinessDays === 5
                    && $rates->terms->charterBalanceDays === 120),
            ],
            'single-triple' => [
                'display' => '+'.$rates->rules->singleSupplementPct.'% · −'.$rates->rules->tripleDiscountPct.'% × 3',
                'differs' => ! ($rates->rules->singleSupplementPct === 75 && $rates->rules->tripleDiscountPct === 10),
            ],
            'ops-004-child-discount' => [
                'display' => '−'.$rates->rules->childDiscountPct.'% · max '.$rates->rules->childDiscountsPerAdult.'/adult, '.$rates->rules->childDiscountsPerCabin.'/cabin',
                'differs' => ! ($rates->rules->childDiscountPct === 15
                    && $rates->rules->childDiscountsPerAdult === 1
                    && $rates->rules->childDiscountsPerCabin === 2),
            ],
            'back-to-back' => [
                'display' => '−'.$rates->rules->backToBackPct.'% both weeks · cabins only',
                'differs' => $rates->rules->backToBackPct !== 5,
            ],
            'festive-supplement' => [
                'display' => '+'.Money::format($rates->rules->festiveSupplementPp).' / guest · +'.Money::format($rates->rules->festiveSupplementCharter).' / charter',
                'differs' => ! ($rates->rules->festiveSupplementPp === 750 && $rates->rules->festiveSupplementCharter === 12000),
            ],
            default => ['display' => '—', 'differs' => null],
        };
    }

    /**
     * @return array{display: string, differs: bool}
     */
    private static function fin001Base(RatesDocument $rates): array
    {
        $year = $rates->year(2027);

        return [
            'display' => $year === null
                ? '—'
                : Money::format($year->suitePp).' · '.Money::format($year->ownerPp).' · '.Money::format($year->charterWeek),
            'differs' => ! ($year !== null
                && $year->suitePp === 13300
                && $year->ownerPp === 25000
                && $year->charterWeek === 199500),
        ];
    }

    /**
     * @return array{display: string, differs: bool}
     */
    private static function fin001Annual(RatesDocument $rates): array
    {
        $years = $rates->years;
        $parts = [];
        $matches = count($years) > 1;

        for ($index = 1, $count = count($years); $index < $count; $index++) {
            $previous = $years[$index - 1]->suitePp;
            $current = $years[$index]->suitePp;
            $pct = $previous > 0 ? (($current - $previous) / $previous) * 100 : 0.0;
            $parts[] = $years[$index]->year.' '.number_format($pct, 1).'%';

            if ($previous <= 0 || abs($pct - 5) >= 0.1) {
                $matches = false;
            }
        }

        return [
            'display' => $parts === [] ? '—' : 'published '.implode(', ', $parts),
            'differs' => ! $matches,
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
            'guests-per-cabin' => [
                'display' => $engine->guests->maxPerCabin.' guests',
                'differs' => $engine->guests->maxPerCabin !== 3,
            ],
            'fin-004-galapagos-fees' => self::fin004($engine),
            'ops-009-charter-sla' => self::charterSla($engine, $current),
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
     * @return array{display: string, differs: bool}
     */
    private static function fin004(EngineSettingsDocument $engine): array
    {
        $png = $engine->fees->png;
        $source = [
            'foreign_over_12' => 200,
            'foreign_12_and_under' => 100,
            'can_adult' => 100,
            'can_minor' => 30,
            'national_or_resident' => 30,
            'exempt_under_age' => 2,
            'tct_pp' => 20,
        ];
        $current = [
            ...$png->toArray(),
            'tct_pp' => $engine->fees->tctPp,
        ];

        return [
            'display' => Money::format($png->foreignOver12).' / '.Money::format($png->foreign12AndUnder).' · TCT '.Money::format($engine->fees->tctPp),
            'differs' => ! DocumentDiff::equal($current, $source),
        ];
    }

    /**
     * @return array{display: string, differs: bool}
     */
    private static function charterSla(EngineSettingsDocument $engine, CurrentConfig $current): array
    {
        $hours = $engine->charter->responseSlaHours;
        $response = $current->has(ConfigKind::BusinessRules)
            ? $current->businessRules()->sla->responseHours
            : 24;

        return [
            'display' => $hours.' hours',
            'differs' => $hours !== 24 || $hours !== $response,
        ];
    }

    private static function lockedDisplay(string $key): string
    {
        return match ($key) {
            'ops-001-duration' => '7 nights · Sunday → Sunday',
            'ops-002-cabins' => "8 Suites + 1 Owner's Suite · ANAMARA and ANATIVA are identical twins",
            'ops-003-home-port' => 'San Cristóbal (SCY)',
            'ops-005-travel-insurance' => "Passenger's responsibility — declaration mandatory at step 5",
            'ops-007-overdue' => 'Alert the team — never auto-cancel',
            'ops-008-fit-groups' => 'Same rates, same process · coordinator only',
            'r-b5-waitlist' => 'First in, first out',
            'offers-festive' => 'Never',
            'never-overbook' => 'Never — last cabin on hold shows Limited Availability',
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
                'fin-002-cabin-deposit',
                $g,
                'FIN-002',
                'Cabin deposit / balance',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '10% · 90% at T−120',
                null,
                'Quotes, Payments, invoices',
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
                'back-to-back',
                $g,
                '§3.4.1',
                'Back-to-back discount',
                RuleStatus::Confirmed,
                RuleWhere::Rates,
                [],
                '−5% · cabin bookings only (Iconic 12 Sep 2026)',
                null,
                'Cabin quotes',
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
                'Commission payable after cruise',
                RuleStatus::Confirmed,
                ['commission.payable_days_after_cruise'],
                BusinessRulesDocument::sourceDisplay('commission.payable_days_after_cruise'),
                data_get($initial, 'commission.payable_days_after_cruise'),
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
                'Booking drawer (move departure)',
            ),
            self::here(
                'extras-due-hours',
                $g,
                'Iconic',
                'Extras & collected fees — due before departure',
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
                'Pre-trip itinerary — days before departure',
                RuleStatus::Confirmed,
                ['documents.pretrip_days_before'],
                BusinessRulesDocument::sourceDisplay('documents.pretrip_days_before'),
                data_get($initial, 'documents.pretrip_days_before'),
                'Document schedule',
            ),
            self::here(
                'voucher-days-before',
                $g,
                'J7',
                'Transfer voucher — days before departure',
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
            new RuleDefinition(
                'ops-009-charter-sla',
                $g,
                'OPS-009',
                'Charter enquiry response (charter page)',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                '24 hours',
                null,
                'Charter page',
                link: self::LINK_ENGINE,
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
                'dpng-manifest',
                $g,
                'OPS-013',
                'DPNG manifest deadline — FIT / charter',
                RuleStatus::Confirmed,
                ['manifests.dpng_fit_days', 'manifests.dpng_charter_days'],
                BusinessRulesDocument::sourceDisplay('manifests.dpng_fit_days'),
                [
                    'manifests.dpng_fit_days' => data_get($initial, 'manifests.dpng_fit_days'),
                    'manifests.dpng_charter_days' => data_get($initial, 'manifests.dpng_charter_days'),
                ],
                'Guest-details reminders, booking drawer',
            ),
            self::here(
                'captain-manifest',
                $g,
                'N4',
                'Captain\'s manifest deadline',
                RuleStatus::Confirmed,
                ['manifests.captain_days'],
                BusinessRulesDocument::sourceDisplay('manifests.captain_days'),
                data_get($initial, 'manifests.captain_days'),
                'Documents & Manifests',
            ),
            self::here(
                'manifest-chase',
                $g,
                'N5',
                'Passenger-data chaser — days before the DPNG due date',
                RuleStatus::PendingClient,
                ['manifests.chase_days_before_due'],
                BusinessRulesDocument::sourceDisplay('manifests.chase_days_before_due'),
                data_get($initial, 'manifests.chase_days_before_due'),
                'Documents & Manifests',
            ),
            self::here(
                'low-occupancy-alert',
                $g,
                '§10',
                'Low-occupancy alert',
                RuleStatus::Confirmed,
                ['alerts.low_occupancy_pct', 'alerts.low_occupancy_days_before'],
                BusinessRulesDocument::sourceDisplay('alerts.low_occupancy_pct'),
                [
                    'alerts.low_occupancy_pct' => data_get($initial, 'alerts.low_occupancy_pct'),
                    'alerts.low_occupancy_days_before' => data_get($initial, 'alerts.low_occupancy_days_before'),
                ],
                'Departure alerts',
            ),
        ];
    }

    /**
     * @return list<RuleDefinition>
     */
    private static function guestsRows(): array
    {
        $g = RuleGroup::GuestsCapacity;

        return [
            new RuleDefinition(
                'ops-004-child-age',
                $g,
                'OPS-004',
                'Minimum child age (on departure day)',
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
                'guests-per-cabin',
                $g,
                'Iconic',
                'Guests per cabin',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                '3 guests (confirmed 12 Sep 2026)',
                null,
                'Engine cabin step, New reservation',
                link: self::LINK_ENGINE,
            ),
            new RuleDefinition(
                'fin-004-galapagos-fees',
                $g,
                'FIN-004',
                'Galápagos fees (PNG foreign >12 / ≤12, TCT)',
                RuleStatus::Confirmed,
                RuleWhere::EngineSettings,
                [],
                'USD 200 / 100 · TCT 20 (CAN & nationals lower; <2 exempt)',
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
                'Sales open / first cruise',
                RuleStatus::Confirmed,
                RuleWhere::Departures,
                [],
                'Sales open 1 Nov 2026 · first cruise 7 Nov 2027',
                null,
                'Engine calendar',
                note: 'Iconic 12 Sep 2026: keep OPS-006 — sales open 1 Nov 2026, first cruise 7 Nov 2027. The rest of the 2027 itinerary calendar is still pending (PRO-001).',
                link: self::LINK_DEPARTURES,
            ),
            self::here(
                'nps-survey',
                $g,
                'N8',
                'Post-trip survey — delay, alert and review thresholds',
                RuleStatus::Confirmed,
                ['nps.survey_hours_after_return', 'nps.alert_below', 'nps.review_request_from'],
                BusinessRulesDocument::sourceDisplay('nps.survey_hours_after_return'),
                [
                    'nps.survey_hours_after_return' => data_get(BusinessRulesDocument::initial(), 'nps.survey_hours_after_return'),
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
     * @return list<RuleDefinition>
     */
    private static function lockedRows(): array
    {
        $g = RuleGroup::StructuralLocked;

        return [
            self::locked(
                'ops-001-duration',
                $g,
                'OPS-001',
                'Duration',
                '7 nights, Sun → Sun',
                'Every departure, rate and itinerary assumes 7 nights — changing it is a rebuild, not a setting.',
                'Departures, engine',
            ),
            self::locked(
                'ops-002-cabins',
                $g,
                'OPS-002',
                'Cabins per property',
                '9 cabins',
                'Physical inventory — fixed by the property. Both properties share the same hull, layout, cabin numbering and rates (Iconic 12 Sep 2026).',
                'Calendar, Property Layout, engine deck plan',
            ),
            self::locked(
                'ops-003-home-port',
                $g,
                'OPS-003',
                'Home port',
                'SCY',
                'Set per itinerary (embark/disembark) — change it in Itineraries.',
                'Itineraries, engine',
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
                'Offers on festive departures',
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
                'Inventory rule — the last cabin on hold shows Limited Availability; the system never sells past physical capacity.',
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
        );
    }
}
