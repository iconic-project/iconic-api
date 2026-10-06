<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\DeliveryStatus;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Payment;
use App\Support\Automations\AutomationGate;
use App\Support\Crm\TaskSweep;
use App\Support\Money;
use App\Support\Payments\WireWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AlertSweep
{
    public function __construct(
        private readonly RaiseAlert $raise,
        private readonly ResolveAlert $resolve,
    ) {}

    public function run(): void
    {
        $this->resolveCleared();
        $this->raiseMissing();
    }

    public function onBookingCreated(Booking $booking): void
    {
        if ($booking->status === BookingStatus::OnHoldAgency) {
            $this->raiseCap($booking);
        }
    }

    public function onBookingStatusChanged(Booking $booking, BookingStatus $from, BookingStatus $to): void
    {
        if ($to === BookingStatus::OnHoldAgency) {
            $this->raiseCap($booking);
        }

        if ($from === BookingStatus::OnHoldAgency && $to !== BookingStatus::OnHoldAgency) {
            $this->resolveBase(AlertKeys::cap($booking->id), 'the booking left ON_HOLD_AGENCY');
        }

        if (in_array($from, [BookingStatus::Confirmed, BookingStatus::OnHoldAgency], true)
            && ! in_array($to, [BookingStatus::Confirmed, BookingStatus::OnHoldAgency], true)
        ) {
            $this->resolveBase(
                AlertKeys::confirmedOnCheckIn($booking->id),
                'the booking left '.$from->value,
            );
        }

        $this->resolveOverdueIfLeftScope($booking->id);
    }

    public function onOverdueFlagged(Booking $booking): void
    {
        $row = $this->overdueQuery()->where('bookings.id', $booking->id)->first();

        if ($row instanceof Booking) {
            $this->raiseOverdue($row);
        }
    }

    public function onPaymentAwaitingWire(Payment $payment): void
    {
        $ends = WireWindow::endsAtFor($payment);

        if ($ends instanceof CarbonImmutable && $ends->lt(now())) {
            $this->raiseWire($payment);
        }
    }

    public function onPaymentSettled(Payment $payment): void
    {
        $this->resolveBase(AlertKeys::wire($payment->id), 'the wire was received or released');
        $this->resolveOverdueIfLeftScope($payment->booking_id);
    }

    public function onDeliveryRecorded(Delivery $delivery): void
    {
        if (in_array($delivery->status, [DeliveryStatus::Failed, DeliveryStatus::Blocked], true)) {
            $this->raiseDelivery($delivery);
        }

        if ($delivery->status === DeliveryStatus::Sent) {
            $this->resolveDeliveriesSupersededBy($delivery);
        }
    }

    private function resolveCleared(): void
    {
        $this->resolveOverdueLeftScope();
        $this->resolveOverdueDueDateChanged();
        $this->resolveConfirmedOnCheckIn();
        $this->resolveCaps();
        $this->resolveWires();
        $this->resolveSlas();
        $this->resolveNps();
        $this->resolveDeliveries();
    }

    private function raiseMissing(): void
    {
        $this->overdueQuery()->orderBy('bookings.id')->each(function (Booking $booking): void {
            $this->raiseOverdue($booking);
        });

        Booking::query()
            ->where('status', BookingStatus::OnHoldAgency)
            ->orderBy('id')
            ->each(function (Booking $booking): void {
                $this->raiseCap($booking);
            });

        Payment::query()->pastWireWindow()->with('booking')->orderBy('payments.id')->each(function (Payment $payment): void {
            $this->raiseWire($payment);
        });

        CrmTask::query()
            ->where('status', TaskStatus::Open)
            ->where('source', TaskSource::System)
            ->where('due_at', '<', now())
            ->orderBy('id')
            ->each(function (CrmTask $task): void {
                $this->raiseSla($task);
            });

        $this->failedDeliveries()->orderBy('deliveries.id')->each(function (Delivery $delivery): void {
            $this->raiseDelivery($delivery);
        });
    }

    /**
     * @return Builder<Booking>
     */
    private function overdueQuery(): Builder
    {
        [$cruiseSql, $paid] = Booking::cruiseOutstandingSql();

        return Booking::query()
            ->overdue()
            ->select(['bookings.id', 'bookings.reference', 'bookings.request_reference'])
            ->selectRaw('DATE('.Booking::dueDateSql().') as alert_due_date')
            ->selectRaw('('.$cruiseSql.') as alert_outstanding', $paid);
    }

    private function raiseOverdue(Booking $booking): void
    {
        $due = $this->dateString($booking->getAttribute('alert_due_date'));
        $reference = TaskSweep::reference($booking);
        $outstanding = (int) $booking->getAttribute('alert_outstanding');

        $this->raise->handle(
            AlertKind::OverdueBalance,
            AlertKeys::overdue($booking->id, $due),
            'Overdue balance '.$reference,
            $reference.' balance is overdue ('.Money::format($outstanding).' outstanding).',
            bookingId: $booking->id,
        );
    }

    private function raiseCap(Booking $booking): void
    {
        $reference = TaskSweep::reference($booking);

        $this->raise->handle(
            AlertKind::CommissionCap,
            AlertKeys::cap($booking->id),
            'Commission cap '.$reference,
            $reference.' is held at ON_HOLD_AGENCY for a commission above the cap.',
            bookingId: $booking->id,
        );
    }

    private function raiseWire(Payment $payment): void
    {
        $payment->loadMissing('booking');
        $reference = TaskSweep::reference($payment->booking);

        $this->raise->handle(
            AlertKind::WireNotReceived,
            AlertKeys::wire($payment->id),
            'Wire not received '.$reference,
            $reference.' wire of '.Money::format($payment->amount).' has passed its window.',
            bookingId: $payment->booking->id,
            paymentId: $payment->id,
        );
    }

    private function raiseSla(CrmTask $task): void
    {
        $taskKey = is_string($task->idempotency_key) && $task->idempotency_key !== ''
            ? $task->idempotency_key
            : 'task:'.$task->id;

        $this->raise->handle(
            AlertKind::SlaBreach,
            AlertKeys::sla($taskKey),
            'SLA breach '.$task->title,
            $task->title.' is past its due time.',
            bookingId: $task->booking_id,
            paymentId: $task->payment_id,
            crmTaskId: $task->id,
        );
    }

    private function raiseDelivery(Delivery $delivery): void
    {
        $delivery->loadMissing('booking');
        $reference = TaskSweep::reference($delivery->booking);
        $kind = $delivery->kind;

        $this->raise->handle(
            AlertKind::DeliveryFailed,
            AlertKeys::delivery($delivery->document_id, $delivery->booking_id, $kind),
            'Delivery failed '.$reference,
            $reference.' '.$kind->label().' delivery '.$delivery->status->value.'.',
            bookingId: $delivery->booking_id,
            deliveryId: $delivery->id,
        );
    }

    private function resolveOverdueLeftScope(): void
    {
        Alert::query()
            ->where('kind', AlertKind::OverdueBalance)
            ->unresolved()
            ->whereNotIn('booking_id', Booking::query()->overdue()->select('bookings.id'))
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'the overdue flag cleared');
            });
    }

    private function resolveOverdueDueDateChanged(): void
    {
        $due = Booking::dueDateSql();

        $changed = DB::table('alerts')
            ->join('bookings', 'bookings.id', '=', 'alerts.booking_id')
            ->where('alerts.kind', AlertKind::OverdueBalance->value)
            ->whereNull('alerts.resolved_at')
            ->whereNull('bookings.deleted_at')
            ->whereIn('alerts.booking_id', Booking::query()->overdue()->select('bookings.id'))
            ->whereRaw('DATE('.$due.') <> SUBSTRING_INDEX(alerts.base_key, \':\', -1)')
            ->select('alerts.id')
            ->selectRaw('DATE('.$due.') as current_due')
            ->orderBy('alerts.id')
            ->get();

        foreach ($changed as $row) {
            $alert = Alert::query()->find($row->id);

            if ($alert instanceof Alert) {
                $this->resolve->handle($alert, 'Due date changed to '.$this->dateString($row->current_due));
            }
        }
    }

    private function resolveOverdueIfLeftScope(int $bookingId): void
    {
        $still = Booking::query()->overdue()->where('bookings.id', $bookingId)->exists();

        if ($still) {
            return;
        }

        Alert::query()
            ->where('kind', AlertKind::OverdueBalance)
            ->where('booking_id', $bookingId)
            ->unresolved()
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'the overdue flag cleared');
            });
    }

    private function resolveConfirmedOnCheckIn(): void
    {
        Alert::query()
            ->where('kind', AlertKind::ConfirmedAtDeparture)
            ->unresolved()
            ->whereNotIn('booking_id', Booking::query()->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::OnHoldAgency,
            ])->select('id'))
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'the booking left CONFIRMED or ON_HOLD_AGENCY');
            });
    }

    private function resolveCaps(): void
    {
        Alert::query()
            ->where('kind', AlertKind::CommissionCap)
            ->unresolved()
            ->whereNotIn('booking_id', Booking::query()->where('status', BookingStatus::OnHoldAgency)->select('id'))
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'the booking left ON_HOLD_AGENCY');
            });
    }

    private function resolveWires(): void
    {
        Alert::query()
            ->where('kind', AlertKind::WireNotReceived)
            ->unresolved()
            ->whereNotIn('payment_id', Payment::query()->pastWireWindow()->select('payments.id'))
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'the wire was received or released');
            });
    }

    private function resolveNps(): void
    {
        Alert::query()
            ->where('kind', AlertKind::NpsLow)
            ->unresolved()
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $task = $alert->crm_task_id !== null ? CrmTask::query()->find($alert->crm_task_id) : null;

                if ($task instanceof CrmTask && $task->status === TaskStatus::Open) {
                    return;
                }

                $this->resolve->handle($alert, 'the task closed');
            });
    }

    private function resolveSlas(): void
    {
        $open = CrmTask::query()
            ->where('status', TaskStatus::Open)
            ->where('source', TaskSource::System)
            ->where('due_at', '<', now())
            ->pluck('id');

        Alert::query()
            ->where('kind', AlertKind::SlaBreach)
            ->unresolved()
            ->orderBy('id')
            ->each(function (Alert $alert) use ($open): void {
                if ($alert->crm_task_id !== null && $open->contains($alert->crm_task_id)) {
                    return;
                }

                $task = $alert->crm_task_id !== null ? CrmTask::query()->find($alert->crm_task_id) : null;
                $fact = $task instanceof CrmTask && $task->status === TaskStatus::Open
                    ? 'the task is no longer past its due time'
                    : 'the task closed';

                $this->resolve->handle($alert, $fact);
            });
    }

    /**
     * @return Builder<Delivery>
     */
    private function failedDeliveries(): Builder
    {
        return Delivery::query()
            ->where(function ($status): void {
                $status->where('deliveries.status', DeliveryStatus::Failed->value)
                    ->orWhere(function ($blocked): void {
                        $blocked->where('deliveries.status', DeliveryStatus::Blocked->value);
                        AutomationGate::excludeSkips($blocked);
                    });
            })
            ->whereNotExists(function ($later): void {
                $later->from('deliveries as sent')
                    ->where('sent.status', DeliveryStatus::Sent->value)
                    ->whereColumn('sent.id', '>', 'deliveries.id')
                    ->where(function ($match): void {
                        $match->where(function ($document): void {
                            $document->whereNotNull('deliveries.document_id')
                                ->whereColumn('sent.document_id', 'deliveries.document_id');
                        })->orWhere(function ($kind): void {
                            $kind->whereNull('deliveries.document_id')
                                ->whereColumn('sent.booking_id', 'deliveries.booking_id')
                                ->whereColumn('sent.kind', 'deliveries.kind');
                        });
                    });
            });
    }

    private function resolveDeliveries(): void
    {
        Alert::query()
            ->where('kind', AlertKind::DeliveryFailed)
            ->unresolved()
            ->whereExists(function ($later): void {
                $later->from('deliveries as failed')
                    ->join('deliveries as sent', function ($join): void {
                        $join->where('sent.status', DeliveryStatus::Sent->value)
                            ->whereColumn('sent.id', '>', 'failed.id')
                            ->where(function ($match): void {
                                $match->where(function ($document): void {
                                    $document->whereNotNull('failed.document_id')
                                        ->whereColumn('sent.document_id', 'failed.document_id');
                                })->orWhere(function ($kind): void {
                                    $kind->whereNull('failed.document_id')
                                        ->whereColumn('sent.booking_id', 'failed.booking_id')
                                        ->whereColumn('sent.kind', 'failed.kind');
                                });
                            });
                    })
                    ->whereColumn('failed.id', 'alerts.delivery_id');
            })
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'a later delivery was sent');
            });
    }

    private function resolveDeliveriesSupersededBy(Delivery $sent): void
    {
        if ($sent->status !== DeliveryStatus::Sent) {
            return;
        }

        Alert::query()
            ->where('kind', AlertKind::DeliveryFailed)
            ->unresolved()
            ->whereHas('delivery', function ($failed) use ($sent): void {
                $failed->where('id', '<', $sent->id)
                    ->where(function ($match) use ($sent): void {
                        if ($sent->document_id !== null) {
                            $match->where('document_id', $sent->document_id);
                        } else {
                            $match->whereNull('document_id')
                                ->where('booking_id', $sent->booking_id)
                                ->where('kind', $sent->kind);
                        }
                    });
            })
            ->orderBy('id')
            ->each(function (Alert $alert): void {
                $this->resolve->handle($alert, 'a later delivery was sent');
            });
    }

    private function resolveBase(string $baseKey, string $fact): void
    {
        Alert::query()
            ->where('base_key', $baseKey)
            ->unresolved()
            ->orderBy('id')
            ->each(function (Alert $alert) use ($fact): void {
                $this->resolve->handle($alert, $fact);
            });
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        return substr((string) $value, 0, 10);
    }
}
