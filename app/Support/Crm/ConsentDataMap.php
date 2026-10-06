<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Services\Config\CurrentConfig;

final class ConsentDataMap
{
    /**
     * Personal-data map (doc 07 §8) as this system implements it.
     *
     * @return list<array{
     *     data: string,
     *     stored_in: string,
     *     in_crm: string,
     *     retention: string,
     *     rule_key: string|null,
     *     rule_value: int|null
     * }>
     */
    public static function rows(): array
    {
        $retention = app(CurrentConfig::class)->businessRules()->retention;

        return [
            [
                'data' => 'Name, email, phone, country, language',
                'stored_in' => 'CRM',
                'in_crm' => 'yes',
                'retention' => 'Kept on the contact. No purge rule is published (doc 07 §8 describes 3 years without a booking).',
                'rule_key' => null,
                'rule_value' => null,
            ],
            [
                'data' => 'Passport number',
                'stored_in' => 'RMS',
                'in_crm' => 'never',
                'retention' => 'Encrypted. Anonymised after check-out.',
                'rule_key' => 'retention.passport_months_after_check_out',
                'rule_value' => $retention->passportMonthsAfterCheckOut,
            ],
            [
                'data' => 'Date of birth, nationality',
                'stored_in' => 'RMS',
                'in_crm' => 'never',
                'retention' => 'Not encrypted. Not purged by the retention job. Stripped from every CRM response.',
                'rule_key' => null,
                'rule_value' => null,
            ],
            [
                'data' => 'Medical, dietary and mobility notes',
                'stored_in' => 'RMS',
                'in_crm' => 'never',
                'retention' => 'Encrypted. Purged after check-out.',
                'rule_key' => 'retention.medical_days_after_check_out',
                'rule_value' => $retention->medicalDaysAfterCheckOut,
            ],
            [
                'data' => 'Card data',
                'stored_in' => 'Stripe',
                'in_crm' => 'no',
                'retention' => 'Held by Stripe. Iconic stores no PAN.',
                'rule_key' => null,
                'rule_value' => null,
            ],
            [
                'data' => 'Invoices, payments, commissions',
                'stored_in' => 'RMS',
                'in_crm' => 'reference',
                'retention' => 'Kept. No retention job deletes them (doc 07 §8 describes 7 years).',
                'rule_key' => null,
                'rule_value' => null,
            ],
            [
                'data' => 'Conversations (email / WhatsApp)',
                'stored_in' => 'Not built',
                'in_crm' => 'not_built',
                'retention' => 'Not stored (M10). Doc 07 §8 describes 5 years.',
                'rule_key' => null,
                'rule_value' => null,
            ],
            [
                'data' => 'Behavioural events',
                'stored_in' => 'CRM',
                'in_crm' => 'yes',
                'retention' => 'Raw events are deleted after retention.behavioural_raw_months. Unstitched anonymous events use retention.behavioural_unstitched_days ('.$retention->behaviouralUnstitchedDays.' days).',
                'rule_key' => 'retention.behavioural_raw_months',
                'rule_value' => $retention->behaviouralRawMonths,
            ],
            [
                'data' => 'Consent register',
                'stored_in' => 'CRM',
                'in_crm' => 'yes',
                'retention' => 'Append-only. Outside the retention job (M1). The IP is stored and never returned.',
                'rule_key' => null,
                'rule_value' => null,
            ],
        ];
    }
}
