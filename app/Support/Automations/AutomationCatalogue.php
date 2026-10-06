<?php

declare(strict_types=1);

namespace App\Support\Automations;

use App\Actions\Charter\CreateCharterEnquiry;
use App\Actions\Charter\IssueCharterProposal;
use App\Actions\GuestExperience\RecordGuestResponse;
use App\Actions\Manifests\SendDataChaser;
use App\Actions\Waitlist\OfferWaitlistEntry;
use App\Enums\AlertKind;
use App\Enums\AutomationAudience;
use App\Enums\AutomationKind;
use App\Enums\DeliveryKind;
use App\Jobs\SendPortalInviteMail;
use App\Listeners\SendOnBookingStatusChanged;
use App\Listeners\SendOnPaymentSettled;
use App\Models\Delivery;
use App\Models\JourneySend;
use App\Support\Alerts\AlertRegistry;
use App\Support\Reports\ReportMailer;

final class AutomationCatalogue
{
    public const REFUSAL = 'This message enforces a rule and cannot be switched off.';

    public const LOCKED = 'The rule behind this message must not depend on a switch.';

    public const BALANCE_REMINDER_21 = 'balance_reminder_21';

    public const BALANCE_REMINDER_7 = 'balance_reminder_7';

    public const PRETRIP = 'pretrip';

    public const DATA_CHASER = 'data_chaser';

    public const REVIEW_REQUEST = 'review_request';

    public const WAITLIST_OFFER = 'waitlist_offer';

    public const CHARTER_PROPOSAL = 'charter_proposal';

    public const CHARTER_ENQUIRY = 'charter_enquiry';

    public const PORTAL_INVITE = 'portal_invite';

    public const REPORT_EMAIL = 'report_email';

    /**
     * Delivery kinds that are one catalogue key. Reminders are resolved from the idempotency key.
     *
     * @var array<string, string>
     */
    private const DELIVERY_KEYS = [
        'INVOICE' => 'booking_confirmation',
        'SUMMARY' => 'booking_summary',
        'RECEIPT' => 'payment_receipt',
        'FINAL_INVOICE' => 'final_invoice',
        'PRETRIP' => self::PRETRIP,
        'PRE_ARRIVAL' => self::PRETRIP,
        'QUESTIONNAIRE' => 'questionnaire',
        'VOUCHER' => 'voucher',
        'DATA_CHASER' => self::DATA_CHASER,
        'SURVEY' => 'survey',
        'REVIEW_REQUEST' => self::REVIEW_REQUEST,
        'WAITLIST_OFFER' => self::WAITLIST_OFFER,
        'CHARTER_PROPOSAL' => self::CHARTER_PROPOSAL,
        'PORTAL_INVITE' => self::PORTAL_INVITE,
    ];

    /**
     * @var array<string, string>
     */
    private const SECTIONS = [
        'a' => 'a · Lead capture & welcome',
        'b' => 'b · Request & confirmation',
        'c' => 'c · Payment calendar',
        'd' => 'd · Extras & ancillaries',
        'e' => 'e · Pre-trip',
        'f' => 'f · Post-trip & loyalty',
        'g' => 'g · Internal alerts — Iconic team',
    ];

    /**
     * @return list<AutomationDefinition>
     */
    public static function all(): array
    {
        return [
            self::row('welcome_web_lead', 'a', 'Welcome — web lead', 'Your Galápagos adventure begins here — Iconic', 'A web lead is captured.', 'Day 0 of nurture_to_request.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('nurture_story', 'a', 'Nurture — sixteen guests', 'Sixteen guests, never more', 'Two days after a nurture enrolment.', 'Day 2 of nurture_to_request.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('nurture_itinerary', 'a', 'Nurture — itinerary spotlight', 'Itinerary spotlight', 'Six days after a nurture enrolment.', 'Day 6 of nurture_to_request.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('nurture_call', 'a', 'Nurture — expedition call', 'A 15-minute expedition call', 'Twelve days after a nurture enrolment.', 'Day 12 of nurture_to_request.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('nurture_concierge', 'a', 'Nurture — concierge note', 'A note from the concierge', 'Twenty-one days after a nurture enrolment.', 'Day 21 of nurture_to_request.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('partner_positioning', 'a', 'Partner positioning', 'Selling Iconic', 'Seven days after an agency is approved.', 'Day 7 of b2b_partner_activation.', 'journey:b2b_partner_activation', AutomationAudience::Customer, true, journeyKey: 'b2b_partner_activation'),
            self::row('partner_incentive', 'a', 'Partner incentive', 'Your first Iconic booking', 'Twenty-one days after an agency is approved.', 'Day 21 of b2b_partner_activation.', 'journey:b2b_partner_activation', AutomationAudience::Customer, true, journeyKey: 'b2b_partner_activation'),
            self::row('portal_invite', 'a', 'Welcome — partner approved', 'Set your Iconic portal password', 'An agency user is invited.', 'Immediate. The accept link is the only copy of the token.', SendPortalInviteMail::class, AutomationAudience::Customer, false),
            self::row('cart_recovery_1', 'a', 'Cart recovery 1', 'Can we help you plan your Galápagos expedition?', 'A stitched abandon_cart with marketing consent and no booking.', '24 hours after enrolment on the abandoned_checkout branch.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('cart_recovery_2', 'a', 'Cart recovery 2', 'Still dreaming of Galápagos? We are here to help.', 'Still no booking after the first cart email.', '48 hours after enrolment on the abandoned_checkout branch.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),
            self::row('cart_recovery_3', 'a', 'Cart recovery 3', 'Can we help plan your trip?', 'Still no booking.', 'Day 7 of the abandoned_checkout branch.', 'journey:nurture_to_request', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'nurture_to_request'),

            self::row('request_acknowledgement', 'b', 'Request acknowledgement', 'We have received your booking — [ID]', 'A booking is created in REQUESTED.', 'Hour 0 of request_to_deposit.', 'journey:request_to_deposit', AutomationAudience::Customer, true, journeyKey: 'request_to_deposit'),
            self::row('deposit_link', 'b', 'Deposit link', 'Complete your reservation — [ID]', 'A request has been held for a day.', 'Day 1 of request_to_deposit. This step does not open a Stripe session.', 'journey:request_to_deposit', AutomationAudience::Customer, true, journeyKey: 'request_to_deposit'),
            self::row('hold_expiry_reminder', 'b', 'Hold expiry reminder', 'Your cabin hold', 'A request has been held for two days.', 'Day 2 of request_to_deposit.', 'journey:request_to_deposit', AutomationAudience::Customer, true, journeyKey: 'request_to_deposit'),
            self::row('booking_confirmation', 'b', 'Booking confirmation', 'Booking confirmation & invoice — {reference}', 'The booking reaches CONFIRMED.', 'Immediate. The invoice PDF is issued, then this email.', SendOnBookingStatusChanged::class, AutomationAudience::Customer, true),
            self::row('booking_summary', 'b', 'Booking summary', 'Your Iconic booking summary — {reference}', 'The booking reaches CONFIRMED.', 'Immediate, with the confirmation. The summary PDF is issued, then this email.', SendOnBookingStatusChanged::class, AutomationAudience::Customer, true),
            self::missing('wire_instructions', 'b', 'Wire instructions', 'Wire transfer instructions — [ID]', 'The payment method is a wire.', 'Immediate.', 'Staff send wire instructions from the RMS. Nothing sends them on a timer.'),
            self::row('waitlist_offer', 'b', 'Waitlist offer', 'A {room type} is free — {stay}', 'The entry\'s whole stay can hold one room of its type.', 'iconic:waitlist-notify. The offer, the follow-up task and this email are one action (O4).', OfferWaitlistEntry::class, AutomationAudience::Customer, false),
            self::row('charter_proposal', 'b', 'Charter proposal', 'Your Iconic charter proposal', 'Staff issue a charter proposal.', 'With the issue. The PDF is stored, then this email.', IssueCharterProposal::class, AutomationAudience::Customer, true),

            self::row('balance_reminder_21', 'c', 'Balance reminder — 21 days', 'Your Iconic balance — due {date}', 'The cruise balance is open and the 21-day reminder date has arrived.', 'The payments.balance_reminder_days slot of 21, from iconic:documents-due.', 'iconic:documents-due', AutomationAudience::Customer, true),
            self::row('balance_reminder_7', 'c', 'Balance reminder — 7 days', 'Your Iconic balance — due {date}', 'The cruise balance is open and the 7-day reminder date has arrived.', 'The payments.balance_reminder_days slot of 7, from iconic:documents-due.', 'iconic:documents-due', AutomationAudience::Customer, true),
            self::row('payment_receipt', 'c', 'Payment confirmation', 'Payment confirmation — {reference}', 'A payment settles.', 'Immediate. The receipt is issued, then this email.', SendOnPaymentSettled::class, AutomationAudience::Customer, true),
            self::row('final_invoice', 'c', 'Final invoice', 'Final invoice — {reference}', 'The booking reaches FULLY_PAID.', 'Immediate. The final invoice is issued, then this email.', SendOnBookingStatusChanged::class, AutomationAudience::Customer, true),
            self::missing('overdue_client', 'c', 'Overdue — day 1', 'Overdue payment — action required — [ID]', 'The cruise balance is past its due date.', 'Day 1.', 'No client overdue email. The flag, the OVERDUE_BALANCE alert and the overdue task are separate rows.'),
            self::missing('escalation_review', 'c', 'Escalation — manual review', '[ESCALATION] Non-payment review required — [ID]', 'An overdue balance waits for a person.', 'After the escalation window.', 'OPS-007 is a person\'s decision. No escalation email is sent.'),

            self::row('extras_offer', 'd', 'Extras offer', 'Curated additions to your Galápagos expedition — Iconic', 'A booking has been confirmed for 7 days.', 'Day 7 of extras_ancillaries.', 'journey:extras_ancillaries', AutomationAudience::Customer, true, journeyKey: 'extras_ancillaries'),
            self::row('extras_second_window', 'd', 'Extras second window', 'Pre and post travel, if you would like it', 'Sixty calendar days before departure.', 'T−60 of extras_ancillaries.', 'journey:extras_ancillaries', AutomationAudience::Customer, true, journeyKey: 'extras_ancillaries'),
            self::row('extras_closing', 'd', 'Extras closing notice', 'Last call for additions — [ID]', 'Departure is inside the extras window.', 'payments.extras_due_hours before departure.', 'journey:extras_ancillaries', AutomationAudience::Customer, true, journeyKey: 'extras_ancillaries'),

            self::row('pretrip', 'e', 'Pre-arrival package', 'Before you arrive — {reference}', 'Check-in is inside documents.pre_arrival_days_before and the booking is confirmed or later.', 'iconic:documents-due. The pre-arrival PDF is issued, then this email.', 'iconic:documents-due', AutomationAudience::Customer, true),
            self::row('questionnaire', 'e', 'Preferences questionnaire', 'Your preferences questionnaire — {reference}', 'The same pre-trip date, for each guest the plan still owes a questionnaire.', 'iconic:documents-due. One send, not a later reminder.', 'iconic:documents-due', AutomationAudience::Customer, true),
            self::row('questionnaire_reminder', 'e', 'Questionnaire reminder', '14 days to go — complete your questionnaire', 'The pre-trip questionnaire is still incomplete.', 'T−14 of ready_to_depart, only while a questionnaire is incomplete.', 'journey:ready_to_depart', AutomationAudience::Customer, true, journeyKey: 'ready_to_depart'),
            self::row('data_chaser', 'e', 'Passport chase', 'Passenger details needed — {reference}', 'Retired with manifests (09 H15). Not scheduled.', 'Retired with manifests (09 H15). Not scheduled.', SendDataChaser::class, AutomationAudience::Customer, false),
            self::row('voucher', 'e', 'Transfer voucher', 'Transfer voucher — {reference}', 'A contracted transfer extra and departure is inside documents.voucher_days_before.', 'iconic:documents-due. The voucher PDF is issued, then this email.', 'iconic:documents-due', AutomationAudience::Customer, true),
            self::row('arrival_instructions', 'e', 'Arrival instructions', 'Almost time! Final instructions for your arrival in San Cristóbal', 'Departure is three days away.', 'T−3 of ready_to_depart.', 'journey:ready_to_depart', AutomationAudience::Customer, true, journeyKey: 'ready_to_depart'),

            self::row('survey', 'f', 'NPS survey', 'Your post-trip survey — {reference}', 'Check-out has passed by nps.survey_hours_after_check_out. A no-show is never surveyed.', 'iconic:nps-survey. The anchor is checked_out_at, otherwise check-out at stay.check_out_time.', 'iconic:nps-survey', AutomationAudience::Customer, true),
            self::row('review_request', 'f', 'Public review request', 'Would you share a review? — {reference}', 'A post-trip score is at least nps.review_request_from and the guest is the contact.', 'When the score is recorded. Marketing: ConsentGate is checked as well, and always.', RecordGuestResponse::class, AutomationAudience::Customer, true, AutomationKind::Marketing),
            self::row('reengagement_6_months', 'f', 'Re-engagement — 6 months', 'Back to Galápagos? A new expedition awaits you', 'Six months after the cruise, with no active booking.', 'Month 6 of reengagement.', 'journey:reengagement', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'reengagement'),
            self::row('reengagement_month_7', 'f', 'Re-engagement — month 7', 'Owner\'s Suite early access', 'Seven months after the cruise, with no active booking.', 'Month 7 of reengagement.', 'journey:reengagement', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'reengagement'),
            self::row('reengagement_month_9', 'f', 'Re-engagement — month 9', 'Bring your people', 'Nine months after the cruise, with no active booking.', 'Month 9 of reengagement.', 'journey:reengagement', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'reengagement'),
            self::row('winback', 'f', 'Win-back', 'Sorry we missed you — what changed?', 'A hold expired, a booking was cancelled, or a deal was marked lost.', 'Day 1 of winback.', 'journey:winback', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'winback'),
            self::row('winback_day_30', 'f', 'Win-back — day 30', 'Alternative departures', 'Thirty days after a lost or expired enquiry.', 'Day 30 of winback.', 'journey:winback', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'winback'),
            self::row('winback_month_6', 'f', 'Win-back — month 6', 'A new season', 'Six months after a lost or expired enquiry.', 'Month 6 of winback.', 'journey:winback', AutomationAudience::Customer, true, AutomationKind::Marketing, journeyKey: 'winback'),

            self::missing('high_value_lead', 'g', 'High-value new lead', '[ALERT] High-value new lead — [Name] — [Country] — USD [Est.]', 'A high-value lead or a charter enquiry.', 'Immediate.', 'No alert kind. The charter enquiry email is its own row.'),
            ...self::alerts(),
            self::row('charter_enquiry', 'g', 'Charter enquiry', 'Charter enquiry · {name}', 'An engine charter enquiry is stored.', 'Immediate, to the reservations mailbox. The enquiry row already exists.', CreateCharterEnquiry::class, AutomationAudience::Staff, true),
            self::row('report_email', 'g', 'Scheduled report', '{report title} {window}', 'A report subscription is due.', 'iconic:reports-send, after the run file exists (O3).', ReportMailer::class, AutomationAudience::Staff, true),
        ];
    }

    /**
     * @return list<AutomationDefinition>
     */
    public static function built(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (AutomationDefinition $row): bool => $row->built,
        ));
    }

    public static function find(string $key): ?AutomationDefinition
    {
        foreach (self::all() as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        return null;
    }

    public static function alertKey(AlertKind $kind): string
    {
        return 'alert:'.$kind->value;
    }

    public static function keyForDelivery(Delivery $delivery): ?string
    {
        if ($delivery->kind === DeliveryKind::Journey) {
            $key = JourneySend::query()->where('delivery_id', $delivery->id)->value('catalogue_key');

            return is_string($key) && $key !== '' ? $key : null;
        }

        if ($delivery->kind === DeliveryKind::Reminder) {
            $days = self::reminderSlot($delivery->idempotency_key);

            if ($days === null) {
                return null;
            }

            $key = 'balance_reminder_'.$days;

            return self::find($key) instanceof AutomationDefinition ? $key : null;
        }

        return self::DELIVERY_KEYS[$delivery->kind->value] ?? null;
    }

    public static function reminderSlot(string $idempotencyKey): ?int
    {
        if (preg_match('/(?:^|:)reminder:\d+:\d{4}-\d{2}-\d{2}:(\d+)(?::|$)/', $idempotencyKey, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return list<AutomationDefinition>
     */
    private static function alerts(): array
    {
        $named = [
            AlertKind::SlaBreach->value => 'Quote SLA breach',
            AlertKind::OverdueBalance->value => 'Overdue payment',
            AlertKind::CommissionCap->value => 'Commission approval',
            AlertKind::LowOccupancy->value => 'Low occupancy',
            AlertKind::NpsLow->value => 'Low NPS',
            AlertKind::DeliveryFailed->value => 'Sync failure',
        ];

        $order = [
            AlertKind::SlaBreach,
            AlertKind::OverdueBalance,
            AlertKind::CommissionCap,
            AlertKind::LowOccupancy,
            AlertKind::NpsLow,
            AlertKind::DeliveryFailed,
            AlertKind::WireNotReceived,
            AlertKind::ConfirmedAtDeparture,
            AlertKind::LedgerDrift,
            AlertKind::CommissionLeakage,
            AlertKind::ManifestDataOverdue,
            AlertKind::ReportFailed,
            AlertKind::CharterDepositDue,
            AlertKind::ArrivalNotCheckedIn,
            AlertKind::InHousePastCheckOut,
            AlertKind::DepartureNotCheckedOut,
        ];

        $rows = [];

        foreach ($order as $kind) {
            $definition = AlertRegistry::get($kind);
            $trigger = $definition->condition;

            if ($kind === AlertKind::DeliveryFailed) {
                $trigger .= ' There is no event bus (L5); this is the failed or blocked delivery.';
            }

            $rows[] = self::row(
                self::alertKey($kind),
                'g',
                $named[$kind->value] ?? $kind->label(),
                self::alertSubject($kind),
                $trigger,
                $definition->emails()
                    ? 'Inbox, and an email to the audience. N1 emails critical alerts only.'
                    : 'Inbox only. N1 does not email this severity.',
                self::alertKey($kind),
                AutomationAudience::Staff,
                false,
                alertKind: $kind->value,
            );
        }

        return $rows;
    }

    private static function alertSubject(AlertKind $kind): string
    {
        return match ($kind) {
            AlertKind::OverdueBalance => 'Overdue balance {reference}',
            AlertKind::CommissionCap => 'Commission cap {reference}',
            AlertKind::WireNotReceived => 'Wire not received {reference}',
            AlertKind::SlaBreach => 'SLA breach {task}',
            AlertKind::DeliveryFailed => 'Delivery failed {reference}',
            AlertKind::ConfirmedAtDeparture => 'Confirmed at check-in {reference}',
            AlertKind::LedgerDrift => 'Ledger drift {reference}',
            AlertKind::CommissionLeakage => 'Commission leakage {reference}',
            AlertKind::LowOccupancy => 'Low occupancy {reference}',
            AlertKind::ManifestDataOverdue => 'Manifest data overdue',
            AlertKind::NpsLow => 'NPS {score} on {reference}',
            AlertKind::ReportFailed => 'Report failed',
            AlertKind::CharterDepositDue => 'Charter deposit due · {reference}',
            AlertKind::ArrivalNotCheckedIn => 'Arrival not checked in {reference}',
            AlertKind::InHousePastCheckOut => 'In house past check-out {reference}',
            AlertKind::DepartureNotCheckedOut => 'Check-out not completed {reference}',
        };
    }

    private static function row(
        string $key,
        string $section,
        string $name,
        string $subject,
        string $trigger,
        string $timing,
        string $location,
        AutomationAudience $audience,
        bool $switchable,
        AutomationKind $kind = AutomationKind::Transactional,
        ?string $alertKind = null,
        ?string $journeyKey = null,
    ): AutomationDefinition {
        return new AutomationDefinition(
            key: $key,
            section: $section,
            sectionLabel: self::SECTIONS[$section],
            name: $name,
            subject: $subject,
            trigger: $trigger,
            timing: $timing,
            location: $location,
            audience: $audience,
            kind: $kind,
            switchable: $switchable,
            lockedReason: $switchable ? null : self::LOCKED,
            built: true,
            notBuiltNote: null,
            alertKind: $alertKind,
            journeyKey: $journeyKey,
        );
    }

    private static function missing(
        string $key,
        string $section,
        string $name,
        string $subject,
        string $trigger,
        string $timing,
        string $note,
    ): AutomationDefinition {
        return new AutomationDefinition(
            key: $key,
            section: $section,
            sectionLabel: self::SECTIONS[$section],
            name: $name,
            subject: $subject,
            trigger: $trigger,
            timing: $timing,
            location: null,
            audience: $section === 'g' ? AutomationAudience::Staff : AutomationAudience::Customer,
            kind: AutomationKind::Transactional,
            switchable: false,
            lockedReason: self::LOCKED,
            built: false,
            notBuiltNote: $note,
            alertKind: null,
            journeyKey: null,
        );
    }
}
