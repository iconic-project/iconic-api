<?php

declare(strict_types=1);

namespace App\Actions\Agencies;

use App\Actions\Action;
use App\Actions\Documents\RecordDelivery;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Jobs\SendPortalInviteMail;
use App\Models\AgencyUser;
use App\Models\Delivery;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Documents\DeliveryKey;
use App\Support\History\History;
use Illuminate\Support\Str;

final class InviteAgencyUser extends Action
{
    public function __construct(
        private CurrentConfig $config,
        private RecordDelivery $recordDelivery,
    ) {}

    public function handle(AgencyUser $agencyUser, ?User $actor, bool $system = false): Delivery
    {
        return $this->transaction(function () use ($agencyUser, $actor, $system): Delivery {
            $token = Str::random(64);
            $sentAt = now();

            $agencyUser->forceFill([
                'invite_token_hash' => hash('sha256', $token),
                'invite_sent_at' => $sentAt,
                'invite_expires_at' => $sentAt->clone()->addDays($this->validDays()),
                'invited_by' => $actor?->id,
            ])->save();

            $delivery = $this->recordDelivery->handle([
                'booking_id' => null,
                'document_id' => null,
                'kind' => DeliveryKind::PortalInvite,
                'idempotency_key' => DeliveryKey::forPortalInvite($agencyUser->id, $sentAt),
                'to' => [$agencyUser->email],
                'cc' => [],
                'subject' => 'Set your Iconic portal password',
                'status' => DeliveryStatus::Queued,
                'triggered_by' => $system ? DeliveryTriggeredBy::System : DeliveryTriggeredBy::User,
            ]);

            SendPortalInviteMail::dispatch($delivery->id, $agencyUser->id, $token)->afterCommit();

            History::record($agencyUser->agency, 'portal.invited', actor: $actor, system: $system, extraContext: [
                'agency_user_id' => $agencyUser->id,
                'email' => $agencyUser->email,
            ]);

            return $delivery;
        });
    }

    private function validDays(): int
    {
        return $this->config->businessRules()->portal->inviteValidDays;
    }
}
