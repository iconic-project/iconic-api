<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Action;
use App\Actions\Crm\RaiseTask;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\Permission;
use App\Enums\PreferredChannel;
use App\Enums\TaskKind;
use App\Mail\Documents\DeliveryMailFactory;
use App\Models\Delivery;
use App\Models\WaitlistEntry;
use App\Services\Config\CurrentConfig;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use App\Support\BusinessTime;
use App\Support\Crm\TaskDue;
use App\Support\History\History;
use App\Support\Waitlist\WaitlistOfferCopy;
use Illuminate\Support\Facades\Mail;

final class OfferWaitlistEntry extends Action
{
    public function __construct(
        private readonly RaiseTask $raiseTask,
        private readonly CurrentConfig $config,
        private readonly AutomationGate $gate,
    ) {}

    /**
     * Sends the offer, or records one blocked delivery when the contact has no address.
     * Returns true only when the email went out.
     */
    public function handle(WaitlistEntry $entry): bool
    {
        return $this->transaction(function () use ($entry): bool {
            $entry->refresh();
            $entry->loadMissing(['contact', 'roomType']);

            if ($entry->removed_at !== null || $entry->notified_at !== null) {
                return false;
            }

            $key = WaitlistOfferCopy::key($entry);

            if (Delivery::query()->where('idempotency_key', $key)->exists()) {
                return false;
            }

            $email = $entry->contact->email;
            $address = is_string($email) ? trim($email) : '';

            if ($address === '') {
                Delivery::query()->create([
                    'booking_id' => null,
                    'kind' => DeliveryKind::WaitlistOffer,
                    'idempotency_key' => $key,
                    'to' => [],
                    'cc' => [],
                    'subject' => WaitlistOfferCopy::subject($entry),
                    'status' => DeliveryStatus::Blocked,
                    'blocked_reason' => 'No email address on the contact',
                    'triggered_by' => DeliveryTriggeredBy::System,
                ]);

                return false;
            }

            $delivery = Delivery::query()->create([
                'booking_id' => null,
                'kind' => DeliveryKind::WaitlistOffer,
                'idempotency_key' => $key,
                'to' => [$address],
                'cc' => [],
                'subject' => WaitlistOfferCopy::subject($entry),
                'status' => DeliveryStatus::Queued,
                'triggered_by' => DeliveryTriggeredBy::System,
            ]);

            $entry->notified_at = now();
            $entry->notified_by = null;
            $entry->notified_channel = PreferredChannel::Email;
            $entry->save();

            History::record($entry, 'waitlist.notified', after: [
                'channel' => PreferredChannel::Email->value,
                'notified_by' => null,
            ], system: true);

            $this->raiseTask->handle(
                TaskKind::WaitlistFollowUp,
                WaitlistOfferCopy::taskKey($entry),
                'Waitlist follow-up · '.$entry->contact->name,
                $entry->check_in->toDateString().' · '.$entry->roomType->code,
                TaskDue::businessDays(BusinessTime::now(), 2, $this->config->businessRules()),
                null,
                Permission::BookingsCreate,
                contactId: $entry->contact_id,
            );

            if (! $this->gate->allows(AutomationCatalogue::WAITLIST_OFFER)) {
                $delivery->status = DeliveryStatus::Blocked;
                $delivery->blocked_reason = $this->gate->reason(AutomationCatalogue::WAITLIST_OFFER);
                $delivery->save();
                History::record($entry, AutomationGate::SKIPPED, after: [
                    'key' => AutomationCatalogue::WAITLIST_OFFER,
                    'delivery_id' => $delivery->id,
                ], reason: $delivery->blocked_reason, system: true);

                return false;
            }

            Mail::send(DeliveryMailFactory::make($delivery, null));

            $delivery->status = DeliveryStatus::Sent;
            $delivery->sent_at = now();
            $delivery->save();

            return true;
        });
    }
}
