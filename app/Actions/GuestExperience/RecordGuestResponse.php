<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Actions\Action;
use App\Actions\Alerts\RaiseAlert;
use App\Actions\Crm\CloseTask;
use App\Actions\Crm\RaiseTask;
use App\Actions\Documents\RecordDelivery;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\ConsentPurpose;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Jobs\SendDeliveryJob;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Alerts\AlertKeys;
use App\Support\Crm\ConsentGate;
use App\Support\Documents\DeliverySubject;
use App\Support\Documents\Recipients;
use App\Support\GuestExperience\ContactGuest;
use App\Support\GuestExperience\SurveyAnswers;
use App\Support\History\History;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RecordGuestResponse extends Action
{
    public const NO_MARKETING_CONSENT = 'no marketing consent on record for this guest';

    public function __construct(
        private readonly CurrentConfig $config,
        private readonly RaiseTask $tasks,
        private readonly RaiseAlert $alerts,
        private readonly CloseTask $close,
        private readonly RecordDelivery $deliveries,
        private readonly Recipients $recipients,
    ) {}

    public function handle(
        Booking $booking,
        Guest $guest,
        SurveyAnswers $answers,
        GuestResponseSource $source,
        ?User $actor = null,
        ?string $actorLabel = null,
    ): GuestResponse {
        /** @var GuestResponse $response */
        $response = $this->transaction(function () use ($booking, $guest, $answers, $source, $actor, $actorLabel): GuestResponse {
            if ($booking->status !== BookingStatus::CheckedOut) {
                throw new HttpException(422, 'A survey response can only be recorded on a completed voyage.');
            }

            if ((int) $guest->booking_id !== (int) $booking->id) {
                throw new HttpException(422, 'That guest is not on this booking.');
            }

            $existing = GuestResponse::query()
                ->where('guest_id', $guest->id)
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof GuestResponse) {
                throw new HttpException(409, 'This guest already has a response for this voyage.');
            }

            $booking->loadMissing(['contact', 'departure.itinerary']);
            $rules = $this->config->businessRules()->nps;
            $respondedAt = Carbon::now();

            $response = GuestResponse::query()->create([
                'guest_id' => $guest->id,
                'booking_id' => $booking->id,
                'score' => $answers->score,
                'recommend' => $answers->recommend,
                'why' => $answers->why,
                'best' => $answers->best,
                'better' => $answers->better,
                'crew' => $answers->crew,
                'call_notes' => $source === GuestResponseSource::Staff ? $answers->callNotes : null,
                'source' => $source,
                'recorded_by' => $source === GuestResponseSource::Staff ? $actor?->id : null,
                'responded_at' => $respondedAt,
            ]);

            $reference = $booking->displayReference() ?? 'booking';
            $follow = [];

            if ($answers->score < $rules->alertBelow) {
                $key = AlertKeys::npsReply($response->id);
                $this->tasks->handle(
                    TaskKind::NpsReply,
                    $key,
                    'Reply to NPS '.$answers->score.' on '.$reference,
                    $guest->displayName().' · score '.$answers->score,
                    CarbonImmutable::instance($respondedAt)->addHours(24),
                    $booking->owner_id,
                    Permission::GuestExperienceManage,
                    contactId: $booking->contact_id,
                    bookingId: $booking->id,
                );
                $this->alerts->handle(
                    AlertKind::NpsLow,
                    $key,
                    'NPS '.$answers->score.' on '.$reference,
                    $guest->displayName().' scored '.$answers->score.' on '.$reference.'.',
                    bookingId: $booking->id,
                    guestResponseId: $response->id,
                );
                $follow[] = 'alert sent to guest experience';
            }

            if ($answers->score >= $rules->reviewRequestFrom) {
                if ($this->sendReview($booking, $guest)) {
                    $follow[] = 'review request sent';
                } else {
                    $follow[] = self::NO_MARKETING_CONSENT;
                }
            }

            $what = 'Post-trip survey recorded — score '.$answers->score;

            if ($follow !== []) {
                $what .= ' · '.implode(' · ', $follow);
            }

            History::record(
                $booking,
                'booking.nps_recorded',
                after: [
                    'guest_id' => $guest->id,
                    'score' => $answers->score,
                    'what' => $what,
                ],
                actor: $source === GuestResponseSource::Staff ? $actor : null,
                actorLabel: $source === GuestResponseSource::GuestLink ? $actorLabel : null,
            );

            if ($source === GuestResponseSource::Staff && $answers->callNotes !== null) {
                $call = CrmTask::query()
                    ->where('idempotency_key', 'post-trip-call:'.$booking->id)
                    ->first();

                if ($call instanceof CrmTask) {
                    $this->close->autoClose($call, 'a staff response with call notes was recorded');
                }
            }

            return $response->refresh();
        });

        return $response;
    }

    private function sendReview(Booking $booking, Guest $guest): bool
    {
        $contact = $booking->contact;

        if (! ContactGuest::guestIs($guest, $contact) || ! ConsentGate::allows($contact, ConsentPurpose::Marketing)) {
            return false;
        }

        $email = $this->recipients->usableAddress($guest->email);

        if ($email === null) {
            return false;
        }

        $key = 'review:'.$guest->id;
        $existing = Delivery::query()->where('idempotency_key', $key)->first();

        if ($existing instanceof Delivery) {
            return $existing->status !== DeliveryStatus::Blocked;
        }

        $delivery = $this->deliveries->handle([
            'booking_id' => $booking->id,
            'document_id' => null,
            'kind' => DeliveryKind::ReviewRequest,
            'idempotency_key' => $key,
            'to' => [$email],
            'cc' => [],
            'subject' => DeliverySubject::forDocument(DeliveryKind::ReviewRequest, $booking),
            'status' => DeliveryStatus::Queued,
            'triggered_by' => DeliveryTriggeredBy::System,
            'blocked_reason' => null,
        ]);

        if ($delivery->wasRecentlyCreated && $delivery->status === DeliveryStatus::Queued) {
            SendDeliveryJob::dispatch($delivery->id)->afterCommit();
        }

        return true;
    }
}
