<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Action;
use App\Enums\AlertKind;
use App\Models\Alert;
use App\Models\CrmTask;
use App\Support\Alerts\AlertRegistry;
use App\Support\History\History;
use Illuminate\Database\UniqueConstraintViolationException;

final class RaiseAlert extends Action
{
    public function handle(
        AlertKind $kind,
        string $baseKey,
        string $title,
        string $sentence,
        ?int $bookingId = null,
        ?int $agencyId = null,
        ?int $paymentId = null,
        ?int $deliveryId = null,
        ?int $guestResponseId = null,
        ?int $crmTaskId = null,
    ): Alert {
        $definition = AlertRegistry::get($kind);

        return $this->transaction(function () use ($kind, $definition, $baseKey, $title, $sentence, $bookingId, $agencyId, $paymentId, $deliveryId, $guestResponseId, $crmTaskId): Alert {
            $existing = Alert::query()->where('base_key', $baseKey)->lockForUpdate()->get();
            $open = $existing->first(fn (Alert $alert): bool => $alert->resolved_at === null);

            if ($open instanceof Alert) {
                return $open;
            }

            $key = $existing->isEmpty() ? $baseKey : $baseKey.'#'.($existing->count() + 1);
            $taskId = $crmTaskId ?? CrmTask::query()->where('idempotency_key', $baseKey)->value('id');

            try {
                $alert = Alert::query()->create([
                    'kind' => $kind,
                    'severity' => $definition->severity,
                    'title' => $title,
                    'sentence' => $sentence,
                    'booking_id' => $bookingId,
                    'agency_id' => $agencyId,
                    'payment_id' => $paymentId,
                    'delivery_id' => $deliveryId,
                    'guest_response_id' => $guestResponseId,
                    'crm_task_id' => is_numeric($taskId) ? (int) $taskId : null,
                    'base_key' => $baseKey,
                    'idempotency_key' => $key,
                    'raised_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                $raced = Alert::query()->where('base_key', $baseKey)->whereNull('resolved_at')->lockForUpdate()->first();

                if ($raced instanceof Alert) {
                    return $raced;
                }

                throw $exception;
            }

            History::record($alert, 'alert.raised', after: [
                'kind' => $kind->value,
                'severity' => $definition->severity->value,
                'title' => $title,
            ], system: true);

            return $alert;
        });
    }
}
