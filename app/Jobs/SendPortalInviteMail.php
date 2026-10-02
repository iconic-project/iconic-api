<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Mail\Portal\PortalInviteMail;
use App\Models\AgencyUser;
use App\Models\Delivery;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendPortalInviteMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $deliveryId,
        public int $agencyUserId,
        #[\SensitiveParameter] public string $token,
    ) {}

    public function handle(AutomationGate $gate): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if (! $delivery instanceof Delivery || $delivery->status !== DeliveryStatus::Queued) {
            return;
        }

        $agencyUser = AgencyUser::query()->with('agency')->find($this->agencyUserId);

        if (! $agencyUser instanceof AgencyUser) {
            return;
        }

        if (! $gate->allows(AutomationCatalogue::PORTAL_INVITE)) {
            $gate->blockDelivery($delivery, AutomationCatalogue::PORTAL_INVITE, $agencyUser->agency);

            return;
        }

        $acceptUrl = rtrim((string) config('iconic.portal_url'), '/').'/accept?'.http_build_query([
            'token' => $this->token,
            'email' => $agencyUser->email,
        ]);

        Mail::send(new PortalInviteMail($delivery, $agencyUser, $acceptUrl));

        DB::transaction(function () use ($delivery): void {
            $fresh = Delivery::query()->findOrFail($delivery->id);

            if ($fresh->status === DeliveryStatus::Sent) {
                return;
            }

            $fresh->status = DeliveryStatus::Sent;
            $fresh->sent_at = now();
            $fresh->error = null;
            $fresh->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if (! $delivery instanceof Delivery || $delivery->status === DeliveryStatus::Sent) {
            return;
        }

        DB::transaction(function () use ($delivery, $exception): void {
            $fresh = Delivery::query()->findOrFail($delivery->id);

            if ($fresh->status === DeliveryStatus::Sent) {
                return;
            }

            $fresh->status = DeliveryStatus::Failed;
            $fresh->error = $exception?->getMessage() ?: 'Send failed';
            $fresh->save();
        });
    }
}
