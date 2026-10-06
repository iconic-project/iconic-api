<?php

declare(strict_types=1);

namespace App\Support\Crm;

final class FieldOwnership
{
    /**
     * @return list<array{
     *     object: string,
     *     field_group: string,
     *     system_of_record: string,
     *     read_by: string,
     *     rule: string,
     *     code: string|null
     * }>
     */
    public static function rows(): array
    {
        return [
            [
                'object' => 'Property',
                'field_group' => 'Name, description, media, SEO',
                'system_of_record' => 'RMS',
                'read_by' => 'Engine (published feed) · CRM',
                'rule' => 'The engine renders what the RMS publishes; nothing is authored on the site.',
                'code' => 'App\\Models\\Property',
            ],
            [
                'object' => 'Stay',
                'field_group' => 'Check-in, check-out, property, status, capacity',
                'system_of_record' => 'RMS',
                'read_by' => 'Engine · CRM',
                'rule' => 'A stay is check-in through check-out on one property.',
                'code' => 'App\\Models\\Booking',
            ],
            [
                'object' => 'Availability & holds',
                'field_group' => 'Room state, web hold, request hold',
                'system_of_record' => 'RMS',
                'read_by' => 'Engine · CRM',
                'rule' => 'Derived from bookings, requests, holds and blocks — never typed anywhere. Read directly from the booking tables.',
                'code' => 'App\\Services\\Inventory\\ClaimService',
            ],
            [
                'object' => 'Rates',
                'field_group' => 'Base rate by year and room type, discounts, supplements',
                'system_of_record' => 'RMS',
                'read_by' => 'Engine · CRM',
                'rule' => 'Draft → publish with an approval reference and an append-only history.',
                'code' => 'App\\Services\\Config\\CurrentConfig::rates()',
            ],
            [
                'object' => 'Offers',
                'field_group' => 'Code, benefit, scope, windows, badge, terms',
                'system_of_record' => 'RMS',
                'read_by' => 'Engine · CRM',
                'rule' => 'The CRM builds the audience and the creative; it cannot create a discount.',
                'code' => 'App\\Models\\Offer',
            ],
            [
                'object' => 'Booking',
                'field_group' => 'Reference, status, rooms, pax, totals, balance calendar',
                'system_of_record' => 'RMS',
                'read_by' => 'CRM (read directly from the booking tables)',
                'rule' => 'The pipeline stage follows the booking status, never the reverse.',
                'code' => 'App\\Models\\Booking',
            ],
            [
                'object' => 'Payments & refunds',
                'field_group' => 'Amount, method, settlement',
                'system_of_record' => 'RMS',
                'read_by' => 'CRM (read directly from the booking tables)',
                'rule' => 'Stripe is the gateway; the RMS holds the ledger. The CRM sees amounts and status only — never gateway details.',
                'code' => 'App\\Models\\Payment',
            ],
            [
                'object' => 'Commission',
                'field_group' => 'Rate, cap breach, accrual, payout',
                'system_of_record' => 'RMS',
                'read_by' => 'CRM (read directly from the booking tables)',
                'rule' => 'One ledger, one audit trail. The 12% cap is enforced in the RMS.',
                'code' => 'App\\Models\\Booking',
            ],
            [
                'object' => 'Documents',
                'field_group' => 'Invoice, summary, receipts, vouchers, manifests',
                'system_of_record' => 'RMS',
                'read_by' => 'CRM (delivery record)',
                'rule' => 'One renderer, one numbering sequence, one template version.',
                'code' => 'App\\Models\\Delivery',
            ],
            [
                'object' => 'Guest personal data',
                'field_group' => 'Passport, date of birth, nationality, medical, dietary',
                'system_of_record' => 'RMS',
                'read_by' => 'Not replicated',
                'rule' => 'Encrypted at rest; deliberately absent from the CRM (LEG-002). The sensitive-field guard walks every CRM response.',
                'code' => 'App\\Support\\SensitiveFields',
            ],
            [
                'object' => 'Contact',
                'field_group' => 'Identity, email, phone, country, language, preferred channel',
                'system_of_record' => 'CRM',
                'read_by' => 'RMS (read, on the booking)',
                'rule' => 'Email is the primary key across the application.',
                'code' => 'App\\Models\\Contact',
            ],
            [
                'object' => 'Attribution',
                'field_group' => 'Main channel, channel of origin, UTM first and last touch',
                'system_of_record' => 'CRM',
                'read_by' => 'RMS (written once at booking creation)',
                'rule' => 'Trade attribution wins over marketing last-touch for commission. Booking UTM columns are frozen by a trigger.',
                'code' => 'App\\Support\\Crm\\AttributionTouch',
            ],
            [
                'object' => 'Consent',
                'field_group' => 'Marketing, terms, cancellation, privacy, insurance · text version + timestamp',
                'system_of_record' => 'CRM',
                'read_by' => 'RMS · Engine',
                'rule' => 'Checked at send time, not at enrolment. Read from the consent log.',
                'code' => 'App\\Models\\Consent',
            ],
            [
                'object' => 'Lifecycle & segment',
                'field_group' => 'PROSPECT → MQL → SQL → BOOKED → GUEST → PAST GUEST · HIGH / MID / NEW',
                'system_of_record' => 'CRM',
                'read_by' => 'CRM (read directly from the booking tables)',
                'rule' => 'Derived from RMS status and engine behaviour — never typed.',
                'code' => 'App\\Support\\Crm\\ContactDerived',
            ],
            [
                'object' => 'Deal & pipeline',
                'field_group' => 'Stage, owner, value, SLA timers, loss reason',
                'system_of_record' => 'CRM',
                'read_by' => 'CRM',
                'rule' => 'Stages 1–4 are stored. Stages 5–7 and a bound LOST follow the booking. A stage move never writes a booking.',
                'code' => 'App\\Models\\Deal',
            ],
            [
                'object' => 'Tasks',
                'field_group' => 'Work queue, due dates, escalation',
                'system_of_record' => 'CRM',
                'read_by' => '—',
                'rule' => 'Raised from RMS state and closed when that state clears. Completing a task never writes a booking.',
                'code' => 'App\\Models\\CrmTask',
            ],
            [
                'object' => 'Conversations',
                'field_group' => 'Email and WhatsApp threads, templates, message-ids',
                'system_of_record' => 'CRM',
                'read_by' => 'RMS (link only)',
                'rule' => 'Not built. The RMS will show a link to the thread; it never stores a copy. Sprint 10.',
                'code' => null,
            ],
            [
                'object' => 'Partner relationship',
                'field_group' => 'Contacts, nurture journey, production targets',
                'system_of_record' => 'CRM',
                'read_by' => '—',
                'rule' => 'The agreement and the ledger stay in the RMS.',
                'code' => 'App\\Models\\Agency',
            ],
            [
                'object' => 'Behavioural events',
                'field_group' => 'Page, property, departure, checkout, abandon, consent',
                'system_of_record' => 'Engine',
                'read_by' => 'CRM (all) · RMS (hold and request only)',
                'rule' => 'Anonymous events stitch to a contact on email capture. Stored in behavioural_events.',
                'code' => 'App\\Models\\BehaviouralEvent',
            ],
        ];
    }
}
