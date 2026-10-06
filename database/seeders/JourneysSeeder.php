<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AutomationKind;
use App\Enums\JourneyStepAction;
use App\Enums\JourneySubject;
use App\Models\Journey;
use App\Models\JourneyStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JourneysSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            $steps = $definition['steps'];
            unset($definition['steps']);

            $journey = Journey::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );

            if ($journey->steps()->exists()) {
                if ($key === 'nurture_to_request') {
                    $this->ensureCartSteps($journey);
                }

                continue;
            }

            foreach ($steps as $step) {
                JourneyStep::query()->create([
                    'journey_id' => $journey->id,
                    ...$step,
                ]);
            }

            if ($key === 'nurture_to_request') {
                $this->ensureCartSteps($journey);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            $this->journey(
                'nurture_to_request',
                'Nurture to Request — D2C',
                'Convince considering leads to submit a booking request',
                AutomationKind::Marketing,
                JourneySubject::Contact,
                'engine: lead.captured · abandon_cart — marketing consent required',
                'exits on rms: booking.created',
                null,
                [['fact' => 'booking_created']],
                [
                    $this->send(1, 'Welcome — your Galápagos begins here', 'welcome_web_lead', ['anchor' => 'enrolment', 'amount' => 0, 'unit' => 'hours']),
                    $this->send(2, 'Story: sixteen guests, never more', 'nurture_story', ['anchor' => 'enrolment', 'amount' => 2, 'unit' => 'days']),
                    $this->send(3, 'Itinerary spotlight (matched to interest segment)', 'nurture_itinerary', ['anchor' => 'enrolment', 'amount' => 6, 'unit' => 'days']),
                    $this->send(4, 'Offer a 15-minute expedition call', 'nurture_call', ['anchor' => 'enrolment', 'amount' => 12, 'unit' => 'days']),
                    $this->send(5, 'Concierge note, then rest 3 months', 'nurture_concierge', ['anchor' => 'enrolment', 'amount' => 21, 'unit' => 'days']),
                ],
            ),
            $this->journey(
                'request_to_deposit',
                'Request to Deposit — confirm the booking',
                'Turn held requests into paid deposits',
                AutomationKind::Transactional,
                JourneySubject::Booking,
                'rms: booking.created status REQUESTED (hold active) — transactional',
                'exits on rms: payment.received · hands to sales at 24 h silence',
                'The booking request they submitted.',
                [['fact' => 'payment_settled']],
                [
                    $this->send(1, 'We have received your booking (acknowledgement)', 'request_acknowledgement', ['anchor' => 'enrolment', 'amount' => 0, 'unit' => 'hours']),
                    $this->task(2, 'Sales exec personal note on the preferred channel', 'request_personal_note', ['anchor' => 'enrolment', 'amount' => 4, 'unit' => 'hours']),
                    $this->send(3, 'Stripe deposit link + what happens next', 'deposit_link', ['anchor' => 'enrolment', 'amount' => 1, 'unit' => 'days']),
                    $this->send(4, 'Hold expiry reminder — gentle, no urgency theatre', 'hold_expiry_reminder', ['anchor' => 'enrolment', 'amount' => 2, 'unit' => 'days']),
                ],
            ),
            $this->journey(
                'payment_calendar',
                'Payment Calendar — automated',
                'Collect balances on time without human chasing',
                AutomationKind::Transactional,
                JourneySubject::Booking,
                'rms: booking.status CONFIRMED with balance — transactional',
                'exits on rms: payment.received (balance cleared)',
                'A confirmed booking with an open cruise balance.',
                [['fact' => 'balance_cleared']],
                [
                    $this->pointer(1, 'Balance reminder 21 days ahead', 'balance_reminder_21', ['anchor' => 'balance_due', 'unit' => 'days', 'rule' => 'balance_reminder', 'slot' => 0], ['balance_reminder_21']),
                    $this->pointer(2, 'Balance reminder 7 days ahead', 'balance_reminder_7', ['anchor' => 'balance_due', 'unit' => 'days', 'rule' => 'balance_reminder', 'slot' => 1], ['balance_reminder_7']),
                    $this->pointer(3, 'Overdue notice + internal alert — no auto-cancel (OPS-007)', 'overdue_notice', ['anchor' => 'balance_due', 'unit' => 'days', 'rule' => 'balance_due_plus_day'], ['alert:OVERDUE_BALANCE']),
                ],
            ),
            $this->journey(
                'extras_ancillaries',
                'Extras & Ancillaries',
                'Sell flights, hotels, spa and premium bar before the extras cut-off',
                AutomationKind::Transactional,
                JourneySubject::Booking,
                'rms: booking.status CONFIRMED + 7 days — transactional',
                'exits at departure',
                'The confirmed booking.',
                [['fact' => 'departed', 'after_template' => 'extras_on_board']],
                [
                    $this->send(1, 'Curated additions to your expedition', 'extras_offer', ['anchor' => 'enrolment', 'amount' => 7, 'unit' => 'days']),
                    $this->send(2, 'Second window — pre/post hotel and flights', 'extras_second_window', ['anchor' => 'arrival', 'amount' => -60, 'unit' => 'days']),
                    $this->send(3, 'Last call: extras close before departure', 'extras_closing', ['anchor' => 'arrival', 'unit' => 'hours', 'rule' => 'extras_due_hours']),
                    $this->task(4, 'Purser sells on board — added to the RMS, invoiced after the cruise', 'extras_on_board', ['anchor' => 'arrival', 'amount' => 0, 'unit' => 'days']),
                ],
            ),
            $this->journey(
                'ready_to_depart',
                'Ready to Depart — pre-trip',
                'Guests arrive prepared and documented',
                AutomationKind::Transactional,
                JourneySubject::Booking,
                'rms: booking FULLY_PAID or CONFIRMED approaching departure — transactional',
                'exits at embarkation · ops alert at T−21 if incomplete',
                'A confirmed or fully paid booking and the documents that booking needs.',
                [['fact' => 'embarked']],
                [
                    $this->pointer(1, 'Pre-trip package + preferences questionnaire', 'pretrip', ['anchor' => 'arrival', 'unit' => 'days', 'rule' => 'pretrip_days_before'], ['pretrip', 'questionnaire']),
                    $this->pointer(2, 'Complete-your-reservation chase if passports missing', 'data_chaser', ['anchor' => 'arrival', 'unit' => 'days', 'rule' => 'manifest_chase'], ['data_chaser']),
                    $this->pointer(3, 'Ops alert if passenger data is incomplete', 'manifest_data_overdue', ['anchor' => 'arrival', 'unit' => 'days', 'rule' => 'dpng_due'], ['alert:MANIFEST_DATA_OVERDUE']),
                    $this->send(4, 'Questionnaire reminder + San Cristóbal arrival guide', 'questionnaire_reminder', ['anchor' => 'arrival', 'amount' => -14, 'unit' => 'days'], ['fact' => 'questionnaire_incomplete']),
                    $this->send(5, 'Final instructions — see you Sunday', 'arrival_instructions', ['anchor' => 'arrival', 'amount' => -3, 'unit' => 'days']),
                ],
            ),
            $this->journey(
                'reengagement',
                'Re-engagement — book again',
                'Bring past guests and gone-quiet leads back down the line',
                AutomationKind::Marketing,
                JourneySubject::Contact,
                'rms: cruise.completed +6 months · or nurture exhausted +3 months — marketing consent',
                'HIGH-LTV branch gets personal outreach instead of email',
                null,
                [
                    ['fact' => 'booking_created_after'],
                    ['fact' => 'high_ltv', 'result' => 'SUPPRESSED', 'reason' => 'HIGH-LTV personal outreach', 'task' => true],
                ],
                [
                    $this->send(1, 'New season, new route — welcome back', 'reengagement_6_months', ['anchor' => 'reengagement', 'amount' => 6, 'unit' => 'months']),
                    $this->send(2, 'Owner\'s Suite early access for past guests', 'reengagement_month_7', ['anchor' => 'reengagement', 'amount' => 7, 'unit' => 'months']),
                    $this->send(3, 'Referral invitation — bring your people', 'reengagement_month_9', ['anchor' => 'reengagement', 'amount' => 9, 'unit' => 'months']),
                ],
            ),
            $this->journey(
                'b2b_partner_activation',
                'B2B Partner Activation',
                'Turn advisors and agencies into producing partners',
                AutomationKind::Transactional,
                JourneySubject::Contact,
                'rms: agency.approved · or first client booked',
                'never exits — quarterly cadence continues',
                'The approved agency agreement.',
                [],
                [
                    $this->pointer(1, 'Welcome + rate agreement and materials', 'portal_invite', ['anchor' => 'enrolment', 'amount' => 0, 'unit' => 'hours'], ['portal_invite']),
                    $this->send(2, 'Selling Iconic: positioning one-pager', 'partner_positioning', ['anchor' => 'enrolment', 'amount' => 7, 'unit' => 'days']),
                    $this->send(3, 'First-booking incentive check-in', 'partner_incentive', ['anchor' => 'enrolment', 'amount' => 21, 'unit' => 'days']),
                    $this->task(4, 'Production review + commission statement from the RMS ledger', 'partner_quarterly', ['anchor' => 'previous_step', 'amount' => 3, 'unit' => 'months', 'repeats' => true]),
                ],
            ),
            $this->journey(
                'winback',
                'Win-back — lost and expired',
                'Recover deals lost on dates, price or a lapsed hold',
                AutomationKind::Marketing,
                JourneySubject::Contact,
                'rms: hold.expired · booking.cancelled · crm: deal marked LOST',
                'exits on rms: booking.created',
                null,
                [['fact' => 'booking_created_after']],
                [
                    $this->send(1, 'Sorry we missed you — what changed?', 'winback', ['anchor' => 'enrolment', 'amount' => 1, 'unit' => 'days']),
                    $this->send(2, 'Alternative departures on your dates', 'winback_day_30', ['anchor' => 'enrolment', 'amount' => 30, 'unit' => 'days']),
                    $this->send(3, 'New season announcement', 'winback_month_6', ['anchor' => 'enrolment', 'amount' => 6, 'unit' => 'months']),
                ],
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $exit
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function journey(
        string $key,
        string $name,
        string $goal,
        AutomationKind $kind,
        JourneySubject $subject,
        string $line,
        string $exitSentence,
        ?string $contract,
        array $exit,
        array $steps,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'goal' => $goal,
            'kind' => $kind,
            'subject' => $subject,
            'trigger' => ['line' => $line],
            'exit_conditions' => $exit,
            'exit_sentence' => $exitSentence,
            'contract' => $contract,
            'active' => false,
            'system' => true,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed>  $delay
     * @param  array<string, mixed>|null  $condition
     * @return array<string, mixed>
     */
    private function send(int $position, string $name, string $key, array $delay, ?array $condition = null): array
    {
        return $this->step($position, $name, $key, $delay, JourneyStepAction::Send, $key, null, $condition);
    }

    /**
     * @param  array<string, mixed>  $delay
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pointer(int $position, string $name, string $template, array $delay, array $keys): array
    {
        return $this->step($position, $name, $template, $delay, JourneyStepAction::Pointer, $keys[0], $keys, null);
    }

    /**
     * @param  array<string, mixed>  $delay
     * @return array<string, mixed>
     */
    private function task(int $position, string $name, string $template, array $delay): array
    {
        return $this->step($position, $name, $template, $delay, JourneyStepAction::Task, null, null, null);
    }

    /**
     * @param  array<string, mixed>  $delay
     * @param  list<string>|null  $keys
     * @param  array<string, mixed>|null  $condition
     * @return array<string, mixed>
     */
    private function step(
        int $position,
        string $name,
        string $template,
        array $delay,
        JourneyStepAction $action,
        ?string $catalogueKey,
        ?array $keys,
        ?array $condition,
    ): array {
        $row = [
            'position' => $position,
            'name' => $name,
            'delay' => $delay,
            'template_key' => $template,
            'condition' => $condition,
            'action' => $action,
            'catalogue_key' => $catalogueKey,
            'catalogue_keys' => $keys,
        ];

        if ($this->hasBranchColumn()) {
            $row['branch'] = 'lead';
        }

        return $row;
    }

    private function ensureCartSteps(Journey $journey): void
    {
        if (! $this->hasBranchColumn()) {
            return;
        }

        $steps = [
            [1, 'Cart recovery 1', 'cart_recovery_1', ['anchor' => 'enrolment', 'amount' => 24, 'unit' => 'hours']],
            [2, 'Cart recovery 2', 'cart_recovery_2', ['anchor' => 'enrolment', 'amount' => 48, 'unit' => 'hours']],
            [3, 'Cart recovery 3', 'cart_recovery_3', ['anchor' => 'enrolment', 'amount' => 7, 'unit' => 'days']],
        ];

        foreach ($steps as [$position, $name, $key, $delay]) {
            $exists = JourneyStep::query()
                ->where('journey_id', $journey->id)
                ->where('template_key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            $step = $this->step($position, $name, $key, $delay, JourneyStepAction::Send, $key, null, null);
            $step['branch'] = 'abandoned_checkout';

            JourneyStep::query()->create([
                'journey_id' => $journey->id,
                ...$step,
            ]);
        }
    }

    /**
     * Information schema, not the schema builder. The journeys migration runs
     * this seeder before the branch column exists, in the same process that
     * adds the column afterwards.
     */
    private function hasBranchColumn(): bool
    {
        $row = DB::selectOne(
            "select count(*) as present from information_schema.columns where table_schema = database() and table_name = 'journey_steps' and column_name = 'branch'",
        );

        return (int) ($row->present ?? 0) > 0;
    }
}
