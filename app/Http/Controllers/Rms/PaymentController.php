<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Payments\MarkWireReceived;
use App\Actions\Payments\RecordPayment;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexPaymentsRequest;
use App\Http\Requests\Rms\MarkWireReceivedRequest;
use App\Http\Requests\Rms\RecordPaymentRequest;
use App\Http\Resources\Rms\PaymentOptionsResource;
use App\Http\Resources\Rms\PaymentResource;
use App\Http\Resources\Rms\RecordedPaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\PaymentOptions;
use App\Support\Payments\PaymentsKpis;
use App\Support\Payments\RecordedPayment;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PaymentController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\PaymentResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string|null, per_page: int, to: int|null, total: int, kpis: array{collected: int, deposits: int, pending: int, pending_count: int, overdue_count: int, overdue_amount: int, commission_accrued: int, charter_deposit_pct: int, commission_payable_days: int, commission_cap_pct: int, wire_window_hours: int}}}',
    )]
    public function index(IndexPaymentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $perPage = $request->integer('per_page', 50);
        $search = $request->validated('q');
        $from = $request->validated('from');
        $to = $request->validated('to');
        $kpis = PaymentsKpis::for(
            $actor,
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        $payments = Payment::query()
            ->whereHas('booking')
            ->with(['booking.contact', 'recordedBy'])
            ->when(
                ! $actor->hasPermission(Permission::BookingsViewAll),
                fn (Builder $query) => $query->whereHas(
                    'booking',
                    fn (Builder $booking) => $booking->where('owner_id', $actor->id),
                ),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query) => $query->whereDate('paid_at', '>=', (string) $request->validated('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query) => $query->whereDate('paid_at', '<=', (string) $request->validated('to')),
            )
            ->when(
                $request->filled('booking_id'),
                fn (Builder $query) => $query->where('booking_id', $request->validated('booking_id')),
            )
            ->when(
                $request->filled('kind'),
                fn (Builder $query) => $query->where('kind', PaymentKind::from((string) $request->validated('kind'))),
            )
            ->when(
                $request->filled('method'),
                fn (Builder $query) => $query->where('method', PaymentMethod::from((string) $request->validated('method'))),
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', PaymentStatus::from((string) $request->validated('status'))),
            )
            ->when(is_string($search) && $search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $like = '%'.$search.'%';
                    $inner->where('payments.reference', 'like', $like)
                        ->orWhereHas('booking', function (Builder $booking) use ($like): void {
                            $booking->where('reference', 'like', $like)
                                ->orWhere('request_reference', 'like', $like);
                        });
                });
            })
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return PaymentResource::collection($payments)->additional([
            'meta' => ['kpis' => $kpis],
        ]);
    }

    public function options(): PaymentOptionsResource
    {
        $this->authorize('viewOptions', Payment::class);

        return new PaymentOptionsResource(PaymentOptions::all());
    }

    public function forBooking(Booking $booking): AnonymousResourceCollection
    {
        $this->authorize('view', $booking);

        $payments = $booking->payments()
            ->with(['booking.contact', 'recordedBy'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        return PaymentResource::collection($payments);
    }

    #[DocumentedResponse(
        status: 201,
        type: 'array{id: int, reference: string, date: string, kind: string, method: string, amount: int, status: string, gateway_id: string|null, recorded_by: string|null, can_mark_wire: bool, wire_window_ends_at: string|null, booking: App\\Http\\Resources\\Rms\\BookingResource, warnings: list<string>}',
    )]
    public function store(RecordPaymentRequest $request, Booking $booking, RecordPayment $action): JsonResponse
    {
        $this->authorize('recordPayment', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $recorded = $action->handle($booking, $request->validated(), $actor);

        $recorded = new RecordedPayment(
            $recorded->payment->load(['booking', 'recordedBy']),
            Booking::query()->withLedgerAggregates()->with([
                'property',
                'room.roomType',
                'contact',
                'group.coordinator',
                'owner',
                'ratesVersion',
                'bookingRequest',
                'activeClaims',
            ])->findOrFail($recorded->booking->getKey()),
            $recorded->warnings,
        );

        return (new RecordedPaymentResource($recorded))->response()->setStatusCode(201);
    }

    public function markReceived(MarkWireReceivedRequest $request, Payment $payment, MarkWireReceived $action): PaymentResource
    {
        $this->authorize('markReceived', $payment);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new PaymentResource($action->handle($payment, $request->validated(), $actor));
    }
}
