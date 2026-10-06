<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Models\Alert;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Payment;
use App\Support\Crm\TaskSweep;

final class AlertSubject
{
    /**
     * @return array{type: string, id: int|null, reference: string, href: string}
     */
    public static function describe(Alert $alert): array
    {
        if ($alert->delivery_id !== null) {
            $delivery = $alert->relationLoaded('delivery') ? $alert->delivery : $alert->delivery()->with('booking')->first();
            $reference = $delivery instanceof Delivery
                ? TaskSweep::reference($delivery->booking)
                : 'delivery '.$alert->delivery_id;

            return [
                'type' => 'delivery',
                'id' => $alert->delivery_id,
                'reference' => $reference,
                'href' => '/crm/system/sync',
            ];
        }

        if ($alert->payment_id !== null) {
            $payment = $alert->relationLoaded('payment') ? $alert->payment : $alert->payment()->with('booking')->first();
            $reference = $payment instanceof Payment
                ? TaskSweep::reference($payment->booking)
                : 'payment '.$alert->payment_id;

            return [
                'type' => 'payment',
                'id' => $alert->payment_id,
                'reference' => $reference,
                'href' => '/rms/reservations/bookings?open='.rawurlencode($reference),
            ];
        }

        if ($alert->booking_id !== null) {
            $booking = $alert->relationLoaded('booking') ? $alert->booking : $alert->booking()->first();
            $reference = $booking instanceof Booking ? TaskSweep::reference($booking) : 'booking '.$alert->booking_id;

            return [
                'type' => 'booking',
                'id' => $alert->booking_id,
                'reference' => $reference,
                'href' => '/rms/reservations/bookings?open='.rawurlencode($reference),
            ];
        }

        if ($alert->crm_task_id !== null) {
            $task = $alert->relationLoaded('crmTask') ? $alert->crmTask : $alert->crmTask()->first();
            $reference = $task instanceof CrmTask ? $task->title : 'task '.$alert->crm_task_id;

            return [
                'type' => 'task',
                'id' => $alert->crm_task_id,
                'reference' => $reference,
                'href' => '/crm/sales/tasks',
            ];
        }

        return [
            'type' => 'alert',
            'id' => $alert->id,
            'reference' => $alert->title,
            'href' => '/rms/operations/alerts',
        ];
    }

    public static function href(Alert $alert): string
    {
        return self::describe($alert)['href'];
    }
}
