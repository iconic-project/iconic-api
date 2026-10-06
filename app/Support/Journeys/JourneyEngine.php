<?php

declare(strict_types=1);

namespace App\Support\Journeys;

use App\Actions\Crm\EnrolJourney;
use App\Actions\Crm\RaiseTask;
use App\Actions\Documents\RecordDelivery;
use App\Enums\AutomationKind;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentPurpose;
use App\Enums\DealStage;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\JourneyEnrolmentStatus;
use App\Enums\JourneyStepAction;
use App\Enums\JourneySubject;
use App\Enums\PaymentStatus;
use App\Enums\TaskKind;
use App\Events\AgencyApproved;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Events\DealMarkedLost;
use App\Events\HoldExpired;
use App\Events\PaymentSettled;
use App\Jobs\SendDeliveryJob;
use App\Models\Agency;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\GuestPreference;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use App\Models\JourneySend;
use App\Models\JourneyStep;
use App\Models\MessageTemplateVersion;
use App\Models\Payment;
use App\Services\Config\CurrentConfig;
use App\Support\Automations\AutomationGate;
use App\Support\BusinessTime;
use App\Support\Crm\ConsentGate;
use App\Support\Crm\ContactDerived;
use App\Support\Crm\Suppression;
use App\Support\History\History;
use App\Support\Templates\RenderedTemplate;
use App\Support\Templates\TemplateRenderer;
use App\Support\Templates\TemplateVariableException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class JourneyEngine
{
    private const string HIGH_LTV = 'HIGH-LTV personal outreach';

    public function __construct(
        private readonly JourneyClock $clock,
        private readonly EnrolJourney $enrolments,
        private readonly ConsentGate $consent,
        private readonly AutomationGate $automations,
        private readonly RecordDelivery $deliveries,
        private readonly RaiseTask $tasks,
        private readonly CurrentConfig $config,
        private readonly TemplateRenderer $templates,
    ) {}

    public function run(): int
    {
        $this->sweep();

        $processed = 0;

        JourneyEnrolment::query()
            ->where('status', JourneyEnrolmentStatus::Active)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now())
            ->orderBy('id')
            ->each(function (JourneyEnrolment $enrolment) use (&$processed): void {
                DB::transaction(function () use ($enrolment): void {
                    $this->advance($enrolment);
                });
                $processed++;
            });

        return $processed;
    }

    public function onLeadCaptured(Contact $contact): void
    {
        $this->enrol('nurture_to_request', $contact, null, 'lead');
    }

    public function exitUnsubscribed(Contact $contact): void
    {
        JourneyEnrolment::query()
            ->where('contact_id', $contact->id)
            ->where('status', JourneyEnrolmentStatus::Active)
            ->whereHas('journey', fn ($query) => $query->where('kind', AutomationKind::Marketing))
            ->orderBy('id')
            ->each(function (JourneyEnrolment $enrolment): void {
                $fresh = $enrolment->fresh();

                if (! $fresh instanceof JourneyEnrolment || $fresh->status !== JourneyEnrolmentStatus::Active) {
                    return;
                }

                $this->finish($fresh, JourneyEnrolmentStatus::Exited, 'unsubscribed', 'journey.exited');
            });
    }

    public function onBookingCreated(BookingCreated $event): void
    {
        $booking = $event->booking;

        $this->exitFor('nurture_to_request', $booking->contact_id, null, 'booking.created');
        $this->exitFor('winback', $booking->contact_id, null, 'booking.created');
        $this->exitFor('reengagement', $booking->contact_id, null, 'booking.created');

        if ($booking->status === BookingStatus::Requested) {
            $this->enrol('request_to_deposit', $booking->contact, $booking);
        }

        $this->enrolPartnerOnFirstBooking($booking);
    }

    public function onBookingStatusChanged(BookingStatusChanged $event): void
    {
        $booking = $event->booking;
        $status = $event->to;

        if (in_array($status, [BookingStatus::Confirmed, BookingStatus::FullyPaid], true)) {
            if ($booking->cruiseOutstanding() > 0) {
                $this->enrol('payment_calendar', $booking->contact, $booking);
            }
            $this->enrol('extras_ancillaries', $booking->contact, $booking);
            $this->enrol('ready_to_depart', $booking->contact, $booking);
        }

        if (in_array($status, [BookingStatus::Cancelled, BookingStatus::CancelledPostpaid], true)) {
            $this->enrol('winback', $booking->contact, $booking);
        }

        if ($status === BookingStatus::CheckedOut) {
            $this->enrol('reengagement', $booking->contact, $booking);
        }
    }

    public function onPaymentSettled(PaymentSettled $event): void
    {
        $booking = $event->booking;

        if ($this->depositReceived($booking)) {
            $this->exitFor('request_to_deposit', $booking->contact_id, $booking->id, 'payment.received');
        }

        if ($booking->cruiseOutstanding() <= 0) {
            $this->exitFor('payment_calendar', $booking->contact_id, $booking->id, 'balance cleared');
        }
    }

    public function onHoldExpired(HoldExpired $event): void
    {
        $holder = $event->holder;

        if (! $holder instanceof Booking) {
            return;
        }

        $this->enrol('winback', $holder->contact, $holder);
    }

    public function onAgencyApproved(AgencyApproved $event): void
    {
        $contact = $this->agencyContact($event->agency);

        if ($contact instanceof Contact) {
            $this->enrol('b2b_partner_activation', $contact);
        }
    }

    public function onDealMarkedLost(DealMarkedLost $event): void
    {
        $deal = $event->deal;

        if ($deal->stage !== DealStage::Lost) {
            return;
        }

        $deal->loadMissing('booking');
        $this->enrol('winback', $deal->contact, $deal->booking);
    }

    public function enrol(string $key, Contact $contact, ?Booking $booking = null, string $branch = 'lead'): ?JourneyEnrolment
    {
        $journey = Journey::query()->where('key', $key)->first();

        if (! $journey instanceof Journey || ! $journey->active) {
            return null;
        }

        if ($journey->kind === AutomationKind::Marketing && ! $this->marketingAllowed($contact)) {
            return null;
        }

        if ($journey->subject === JourneySubject::Booking && ! $booking instanceof Booking) {
            return null;
        }

        if ($journey->subject === JourneySubject::Contact && $this->contactEnrolled($journey, $contact, $branch)) {
            return null;
        }

        if ($key === 'nurture_to_request' && $this->hasBooking($contact)) {
            return null;
        }

        try {
            $enrolment = $this->enrolments->handle($journey, $contact, $booking, $branch);

            if ($journey->key === 'reengagement' && $this->isHighLtv($contact)) {
                DB::transaction(function () use ($enrolment): void {
                    $enrolment->load('booking');
                    $this->handoverOnce($enrolment, 'high-ltv', 'Personal outreach for a high-value guest');
                    $this->finish($enrolment, JourneyEnrolmentStatus::Suppressed, self::HIGH_LTV, 'journey.suppressed');
                });
            }

            return $enrolment->fresh();
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function sweep(): void
    {
        $this->sweepNurture();
        $this->sweepReengagementFromNurture();
        $this->sweepMissedBookings();
    }

    private function sweepNurture(): void
    {
        $journey = Journey::query()->where('key', 'nurture_to_request')->where('active', true)->first();

        if (! $journey instanceof Journey) {
            return;
        }

        $ids = BehaviouralEvent::query()
            ->where('name', BehaviouralEventName::AbandonCart)
            ->whereNotNull('contact_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.contact_id', 'behavioural_events.contact_id')
                    ->whereNull('bookings.deleted_at');
            })
            ->distinct()
            ->pluck('contact_id');

        Contact::query()->whereKey($ids)->each(function (Contact $contact): void {
            $this->enrol('nurture_to_request', $contact, null, 'abandoned_checkout');
        });
    }

    private function sweepReengagementFromNurture(): void
    {
        $nurture = Journey::query()->where('key', 'nurture_to_request')->first();

        if (! $nurture instanceof Journey) {
            return;
        }

        JourneyEnrolment::query()
            ->where('journey_id', $nurture->id)
            ->where('status', JourneyEnrolmentStatus::Completed)
            ->whereNotNull('exited_at')
            ->where('exited_at', '<=', now()->subMonths(3))
            ->with('contact')
            ->each(function (JourneyEnrolment $done): void {
                if ($done->exited_at === null || $this->bookedSince($done->contact_id, $done->exited_at)) {
                    return;
                }

                $this->enrol('reengagement', $done->contact);
            });
    }

    private function sweepMissedBookings(): void
    {
        Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::FullyPaid])
            ->with('contact')
            ->each(function (Booking $booking): void {
                if ($booking->cruiseOutstanding() > 0) {
                    $this->enrol('payment_calendar', $booking->contact, $booking);
                }
                $this->enrol('extras_ancillaries', $booking->contact, $booking);
                $this->enrol('ready_to_depart', $booking->contact, $booking);
            });

        Booking::query()
            ->where('status', BookingStatus::CheckedOut)
            ->with('contact')
            ->each(function (Booking $booking): void {
                $this->enrol('reengagement', $booking->contact, $booking);
            });
    }

    private function advance(JourneyEnrolment $enrolment): void
    {
        $guard = 0;

        while ($this->isDue($enrolment)) {
            if (++$guard > 20) {
                break;
            }

            $outcome = $this->step($enrolment);
            $enrolment->refresh();

            if ($outcome !== 'advanced') {
                break;
            }
        }
    }

    private function isDue(JourneyEnrolment $enrolment): bool
    {
        return $enrolment->status === JourneyEnrolmentStatus::Active
            && $enrolment->next_due_at !== null
            && $enrolment->next_due_at->lessThanOrEqualTo(now());
    }

    private function step(JourneyEnrolment $enrolment): string
    {
        $enrolment->load(['journey.steps', 'contact', 'booking']);
        $journey = $enrolment->journey;
        $step = $journey->steps
            ->where('branch', $enrolment->branch)
            ->firstWhere('position', $enrolment->position);

        if (! $step instanceof JourneyStep) {
            $this->finish($enrolment, JourneyEnrolmentStatus::Completed, null, 'journey.completed');

            return 'left';
        }

        if ($this->exitApplies($enrolment, $step)) {
            return 'left';
        }

        if (! $journey->active) {
            return 'stay';
        }

        if ($journey->kind === AutomationKind::Marketing && ! $this->marketingAllowed($enrolment->contact)) {
            $this->finish(
                $enrolment,
                JourneyEnrolmentStatus::Suppressed,
                $this->marketingReason($enrolment->contact),
                'journey.suppressed',
            );

            return 'left';
        }

        if (! $this->conditionHolds($enrolment, $step)) {
            $this->moveOn($enrolment, $step);

            return 'advanced';
        }

        if ($step->action === JourneyStepAction::Pointer) {
            $this->recordPointer($enrolment, $step);
            $this->moveOn($enrolment, $step);

            return 'advanced';
        }

        if ($step->action === JourneyStepAction::Task) {
            $this->raiseHandover($enrolment, $step);
            $this->moveOn($enrolment, $step);

            return 'advanced';
        }

        $catalogueKey = $step->catalogue_key;

        if (is_string($catalogueKey) && $catalogueKey !== '' && ! $this->automations->allows($catalogueKey)) {
            $this->skipOnce($enrolment, $step);

            return 'stay';
        }

        $rendered = $this->rendered($enrolment, $step);

        if (! $rendered instanceof RenderedTemplate) {
            return 'stay';
        }

        $this->send($enrolment, $step, $rendered);
        $this->moveOn($enrolment, $step);

        return 'advanced';
    }

    private function exitApplies(JourneyEnrolment $enrolment, JourneyStep $step): bool
    {
        foreach ($enrolment->journey->exit_conditions as $condition) {
            $fact = $condition['fact'] ?? null;
            $after = $condition['after_template'] ?? null;

            if (is_string($after) && $after === $step->template_key && ! $this->alreadySent($enrolment, $step)) {
                continue;
            }

            if ($fact === 'high_ltv') {
                if ($this->isHighLtv($enrolment->contact)) {
                    $this->handoverOnce($enrolment, 'high-ltv', 'Personal outreach for a high-value guest');
                    $reason = is_string($condition['reason'] ?? null) ? $condition['reason'] : self::HIGH_LTV;
                    $this->finish($enrolment, JourneyEnrolmentStatus::Suppressed, $reason, 'journey.suppressed');

                    return true;
                }

                continue;
            }

            if ($this->fact($enrolment, is_string($fact) ? $fact : '')) {
                $this->finish($enrolment, JourneyEnrolmentStatus::Exited, $this->exitReason($fact), 'journey.exited');

                return true;
            }
        }

        return false;
    }

    private function fact(JourneyEnrolment $enrolment, string $fact): bool
    {
        $booking = $enrolment->booking;

        return match ($fact) {
            'booking_created' => $this->hasBooking($enrolment->contact),
            'booking_created_after' => $this->bookedSince($enrolment->contact_id, $enrolment->enrolled_at),
            'payment_settled' => $booking instanceof Booking && $this->depositReceived($booking),
            'balance_cleared' => $booking instanceof Booking && $booking->cruiseOutstanding() <= 0,
            'departed', 'embarked' => $this->leftTheDock($booking, $fact === 'embarked'),
            default => false,
        };
    }

    private function leftTheDock(?Booking $booking, bool $orStatus): bool
    {
        if (! $booking instanceof Booking) {
            return false;
        }

        if ($orStatus && in_array($booking->status, [BookingStatus::InHouse, BookingStatus::CheckedOut], true)) {
            return true;
        }

        $checkIn = $booking->getAttributes()['check_in'] ?? null;

        if (! is_string($checkIn) || $checkIn === '') {
            return false;
        }

        return BusinessTime::now()->toDateString() >= substr($checkIn, 0, 10);
    }

    private function conditionHolds(JourneyEnrolment $enrolment, JourneyStep $step): bool
    {
        $fact = $step->condition['fact'] ?? null;

        if ($fact !== 'questionnaire_incomplete') {
            return true;
        }

        $booking = $enrolment->booking;

        if (! $booking instanceof Booking) {
            return false;
        }

        $guestIds = $booking->guests()->pluck('id');

        if ($guestIds->isEmpty()) {
            return true;
        }

        $answered = GuestPreference::query()->whereIn('guest_id', $guestIds)->count();

        return $answered < $guestIds->count();
    }

    private function rendered(JourneyEnrolment $enrolment, JourneyStep $step): ?RenderedTemplate
    {
        $version = MessageTemplateVersion::query()
            ->where('published', true)
            ->whereHas('template', fn ($query) => $query->where('key', $step->template_key))
            ->orderByDesc('version')
            ->first();

        if (! $version instanceof MessageTemplateVersion) {
            return null;
        }

        try {
            return $this->templates->render($version, $enrolment->contact, $enrolment->booking);
        } catch (TemplateVariableException) {
            return null;
        }
    }

    private function send(JourneyEnrolment $enrolment, JourneyStep $step, RenderedTemplate $rendered): void
    {
        $address = $this->address($enrolment->contact);
        $delivery = $this->deliveries->handle([
            'booking_id' => $enrolment->booking_id,
            'kind' => DeliveryKind::Journey,
            'idempotency_key' => $this->deliveryKey($enrolment, $step),
            'to' => $address === null ? [] : [$address],
            'cc' => [],
            'subject' => $rendered->subject,
            'status' => $address === null ? DeliveryStatus::Blocked : DeliveryStatus::Queued,
            'blocked_reason' => $address === null ? 'No usable address.' : null,
            'triggered_by' => DeliveryTriggeredBy::System,
        ]);

        $this->recordSend($enrolment, $step, $step->catalogue_key, $delivery, $rendered->version->version);

        if ($delivery->status === DeliveryStatus::Queued && $delivery->wasRecentlyCreated) {
            SendDeliveryJob::dispatch($delivery->id);
        }
    }

    private function recordPointer(JourneyEnrolment $enrolment, JourneyStep $step): void
    {
        foreach ($step->pointerKeys() as $key) {
            $this->recordSend($enrolment, $step, $key, null);
        }
    }

    private function recordSend(JourneyEnrolment $enrolment, JourneyStep $step, ?string $catalogueKey, ?Delivery $delivery, ?int $templateVersion = null): void
    {
        JourneySend::query()->create([
            'journey_enrolment_id' => $enrolment->id,
            'journey_step_id' => $step->id,
            'template_key' => $step->template_key,
            'template_version' => $templateVersion,
            'catalogue_key' => $catalogueKey,
            'delivery_id' => $delivery?->id,
            'sent_at' => now(),
        ]);
    }

    private function raiseHandover(JourneyEnrolment $enrolment, JourneyStep $step): void
    {
        $suffix = (string) $step->id;

        if ($step->repeats() && $enrolment->next_due_at !== null) {
            $suffix .= ':'.$enrolment->next_due_at->utc()->toDateString();
        }

        $this->handoverOnce($enrolment, $suffix, $step->name);
        $this->recordSend($enrolment, $step, null, null);
    }

    private function handoverOnce(JourneyEnrolment $enrolment, string $suffix, string $title): void
    {
        $owner = $enrolment->booking?->owner_id;
        $enrolment->loadMissing('journey');

        $this->tasks->handle(
            kind: TaskKind::JourneyHandover,
            key: 'journey-handover:'.$enrolment->id.':'.$suffix,
            title: $title,
            context: $enrolment->journey->name,
            dueAt: now(),
            ownerId: is_int($owner) ? $owner : null,
            needs: null,
            contactId: $enrolment->contact_id,
            bookingId: $enrolment->booking_id,
        );
    }

    private function moveOn(JourneyEnrolment $enrolment, JourneyStep $current): void
    {
        if ($current->repeats()) {
            $enrolment->next_due_at = Carbon::parse($enrolment->next_due_at)->addMonths(3);
            $enrolment->save();

            return;
        }

        $next = $enrolment->journey->steps
            ->where('branch', $enrolment->branch)
            ->firstWhere('position', $current->position + 1);

        if (! $next instanceof JourneyStep) {
            $this->finish($enrolment, JourneyEnrolmentStatus::Completed, null, 'journey.completed');

            return;
        }

        $enrolment->position = $next->position;
        $enrolment->next_due_at = $this->due($enrolment, $next);
        $enrolment->save();
    }

    private function finish(JourneyEnrolment $enrolment, JourneyEnrolmentStatus $status, ?string $reason, string $event): void
    {
        $enrolment->status = $status;
        $enrolment->exit_reason = $reason;
        $enrolment->exited_at = now();
        $enrolment->next_due_at = null;
        $enrolment->save();

        History::record($enrolment, $event, after: [
            'status' => $status->value,
            'reason' => $reason,
        ], system: true);
    }

    private function skipOnce(JourneyEnrolment $enrolment, JourneyStep $step): void
    {
        $seen = ChangeHistory::query()
            ->where('subject_type', $enrolment->getMorphClass())
            ->where('subject_id', $enrolment->id)
            ->where('event', AutomationGate::SKIPPED)
            ->get()
            ->contains(fn (ChangeHistory $row): bool => ($row->after['step_id'] ?? null) === $step->id);

        if ($seen) {
            return;
        }

        History::record($enrolment, AutomationGate::SKIPPED, after: [
            'step_id' => $step->id,
            'catalogue_key' => $step->catalogue_key,
        ], system: true);
    }

    private function exitFor(string $key, int $contactId, ?int $bookingId, string $reason): void
    {
        $journey = Journey::query()->where('key', $key)->first();

        if (! $journey instanceof Journey) {
            return;
        }

        $query = JourneyEnrolment::query()
            ->where('journey_id', $journey->id)
            ->where('contact_id', $contactId)
            ->where('status', JourneyEnrolmentStatus::Active);

        if ($journey->subject === JourneySubject::Booking && $bookingId !== null) {
            $query->where('booking_id', $bookingId);
        }

        $query->each(function (JourneyEnrolment $enrolment) use ($reason): void {
            DB::transaction(function () use ($enrolment, $reason): void {
                $fresh = $enrolment->fresh();

                if (! $fresh instanceof JourneyEnrolment || $fresh->status !== JourneyEnrolmentStatus::Active) {
                    return;
                }

                $this->finish($fresh, JourneyEnrolmentStatus::Exited, $reason, 'journey.exited');
            });
        });
    }

    private function enrolPartnerOnFirstBooking(Booking $booking): void
    {
        if ($booking->agency_id === null) {
            return;
        }

        $earlier = Booking::query()
            ->where('agency_id', $booking->agency_id)
            ->where('id', '!=', $booking->id)
            ->exists();

        if ($earlier) {
            return;
        }

        $agency = $booking->agency;

        if (! $agency instanceof Agency) {
            return;
        }

        $contact = $this->agencyContact($agency);

        if ($contact instanceof Contact) {
            $this->enrol('b2b_partner_activation', $contact);
        }
    }

    private function agencyContact(Agency $agency): ?Contact
    {
        return ContactDerived::contactForAgency($agency);
    }

    private function marketingAllowed(Contact $contact): bool
    {
        return $this->consent->allows($contact, ConsentPurpose::Marketing) && ! Suppression::applies($contact);
    }

    private function marketingReason(Contact $contact): string
    {
        if (! $this->consent->allows($contact, ConsentPurpose::Marketing)) {
            return 'Marketing consent withdrawn.';
        }

        return 'Suppressed.';
    }

    private function hasBooking(Contact $contact): bool
    {
        return Booking::query()->where('contact_id', $contact->id)->exists();
    }

    private function bookedSince(int $contactId, Carbon $at): bool
    {
        return Booking::query()
            ->where('contact_id', $contactId)
            ->where('created_at', '>', $at)
            ->exists();
    }

    private function depositReceived(Booking $booking): bool
    {
        $paid = array_map(
            fn (PaymentStatus $status): string => $status->value,
            array_values(array_filter(
                PaymentStatus::cases(),
                fn (PaymentStatus $status): bool => $status->countsAsPaid(),
            )),
        );

        return Payment::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', $paid)
            ->exists();
    }

    private function contactEnrolled(Journey $journey, Contact $contact, string $branch): bool
    {
        return JourneyEnrolment::query()
            ->where('journey_id', $journey->id)
            ->where('contact_id', $contact->id)
            ->where('branch', $branch)
            ->exists();
    }

    private function alreadySent(JourneyEnrolment $enrolment, JourneyStep $step): bool
    {
        return JourneySend::query()
            ->where('journey_enrolment_id', $enrolment->id)
            ->where('journey_step_id', $step->id)
            ->exists();
    }

    private function deliveryKey(JourneyEnrolment $enrolment, JourneyStep $step): string
    {
        $key = 'journey:'.$enrolment->id.':'.$step->id;

        if ($step->repeats() && $enrolment->next_due_at !== null) {
            $key .= ':'.$enrolment->next_due_at->utc()->toDateString();
        }

        return $key;
    }

    private function address(Contact $contact): ?string
    {
        $email = strtolower(trim((string) $contact->email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function isHighLtv(Contact $contact): bool
    {
        $sql = ContactDerived::segmentSql($this->config->businessRules()->crm);

        $band = DB::table('contacts')
            ->where('contacts.id', $contact->id)
            ->selectRaw($sql.' as band')
            ->value('band');

        return $band === 'HIGH';
    }

    private function exitReason(mixed $fact): string
    {
        return match ($fact) {
            'booking_created', 'booking_created_after' => 'booking.created',
            'payment_settled' => 'payment.received',
            'balance_cleared' => 'balance cleared',
            'departed' => 'departure',
            'embarked' => 'embarkation',
            default => 'exit',
        };
    }

    private function due(JourneyEnrolment $enrolment, JourneyStep $step): Carbon
    {
        return Carbon::instance($this->clock->dueAt($enrolment, $step));
    }

    private function isDuplicate(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19;
    }
}
