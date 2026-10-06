<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Actions\Action;
use App\Actions\Documents\RecordDelivery;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Jobs\SendDeliveryJob;
use App\Models\Booking;
use App\Models\Delivery;
use App\Support\Documents\DeliverySubject;
use App\Support\GuestExperience\SurveyDispatch;
use App\Support\GuestExperience\SurveyPlan;

final class SendSurveys extends Action
{
    public function __construct(
        private readonly SurveyPlan $plan,
        private readonly RecordDelivery $record,
        private readonly IssueSurveyAccessToken $tokens,
    ) {}

    public function handle(Booking $booking): int
    {
        if ($booking->status === BookingStatus::NoShow) {
            return 0;
        }

        /** @var int $created */
        $created = $this->transaction(function () use ($booking): int {
            $count = 0;

            foreach ($this->plan->dispatches($booking) as $dispatch) {
                if ($this->recordOne($booking, $dispatch)) {
                    $count++;
                }
            }

            return $count;
        });

        return $created;
    }

    private function recordOne(Booking $booking, SurveyDispatch $dispatch): bool
    {
        $existing = Delivery::query()->where('idempotency_key', $dispatch->key)->first();

        if ($existing instanceof Delivery) {
            return false;
        }

        if ($dispatch->blocked()) {
            $this->record->handle([
                'booking_id' => $booking->id,
                'document_id' => null,
                'kind' => DeliveryKind::Survey,
                'idempotency_key' => $dispatch->key,
                'to' => [],
                'cc' => [],
                'subject' => DeliverySubject::forDocument(DeliveryKind::Survey, $booking),
                'status' => DeliveryStatus::Blocked,
                'blocked_reason' => $dispatch->blockedReason,
                'triggered_by' => DeliveryTriggeredBy::System,
            ]);

            return true;
        }

        $this->tokens->handle($booking, $dispatch->guestId, $dispatch->coveredGuestIds);

        $delivery = $this->record->handle([
            'booking_id' => $booking->id,
            'document_id' => null,
            'kind' => DeliveryKind::Survey,
            'idempotency_key' => $dispatch->key,
            'to' => $dispatch->to,
            'cc' => [],
            'subject' => DeliverySubject::forDocument(DeliveryKind::Survey, $booking),
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
