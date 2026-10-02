<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Bookings\CreateReservation;
use App\Actions\Bookings\DecideOverdue;
use App\Actions\Bookings\DeleteBooking;
use App\Actions\Bookings\MoveBooking;
use App\Actions\Bookings\TransitionBooking;
use App\Actions\Bookings\UpdateBooking;
use App\Enums\BookingSegment;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\CabinUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\DeleteBookingRequest;
use App\Http\Requests\Rms\IndexBookingAuditRequest;
use App\Http\Requests\Rms\IndexBookingsRequest;
use App\Http\Requests\Rms\MoveBookingRequest;
use App\Http\Requests\Rms\OverdueDecisionRequest;
use App\Http\Requests\Rms\PreviewMoveBookingRequest;
use App\Http\Requests\Rms\QuoteReservationRequest;
use App\Http\Requests\Rms\StoreReservationRequest;
use App\Http\Requests\Rms\TransitionBookingRequest;
use App\Http\Requests\Rms\UpdateBookingRequest;
use App\Http\Resources\Rms\BookingAuditResource;
use App\Http\Resources\Rms\BookingFormOptionsResource;
use App\Http\Resources\Rms\BookingOwnerResource;
use App\Http\Resources\Rms\BookingResource;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\MovePreviewResource;
use App\Http\Resources\Rms\ReservationCreatedResource;
use App\Http\Resources\Rms\ReservationQuoteResource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Bookings\BookingFormOptions;
use App\Support\BusinessTime;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class BookingController extends Controller
{
    public function index(IndexBookingsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $perPage = $request->integer('per_page', 50);
        $search = $request->validated('q');

        $query = Booking::query()
            ->select('bookings.*')
            ->join('departures', 'departures.id', '=', 'bookings.departure_id')
            ->visibleTo($actor)
            ->departingBetween(
                is_string($request->validated('from')) ? $request->validated('from') : null,
                is_string($request->validated('to')) ? $request->validated('to') : null,
            )
            ->when($request->boolean('mine'), fn (Builder $query) => $query->where('bookings.owner_id', $actor->id))
            ->when(
                $request->filled('segment'),
                fn (Builder $query) => $query->ofSegment(BookingSegment::from((string) $request->validated('segment'))),
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('bookings.status', BookingStatus::from((string) $request->validated('status'))),
            )
            ->when(
                $request->filled('departure_id'),
                fn (Builder $query) => $query->where('bookings.departure_id', $request->validated('departure_id')),
            )
            ->when(
                $request->filled('group_id'),
                fn (Builder $query) => $query->where('bookings.group_id', $request->validated('group_id')),
            )
            ->when(is_string($search) && $search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $like = '%'.$search.'%';
                    $inner->where('bookings.reference', 'like', $like)
                        ->orWhere('bookings.request_reference', 'like', $like)
                        ->orWhereHas('contact', function (Builder $contact) use ($like): void {
                            $contact->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            });

        $kpis = $this->overdueKpis(clone $query);

        $bookings = $query
            ->when($request->boolean('overdue'), fn (Builder $query) => $query->overdue())
            ->when($request->boolean('pending_payment'), fn (Builder $query) => $query->pendingPayment())
            ->withLedgerAggregates()
            ->withGuestSummary()
            ->withChargesSummary()
            ->with([
                'departure.property',
                'departure.itinerary',
                'cabin',
                'contact',
                'group.coordinator',
                'owner',
                'agency',
                'commissionApprovedBy',
                'ratesVersion',
                'bookingRequest',
                'activeClaims',
            ])
            ->orderBy('departures.date')
            ->orderBy('bookings.reference')
            ->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'meta' => ['kpis' => $kpis],
        ]);
    }

    public function formOptions(CurrentConfig $config): BookingFormOptionsResource
    {
        $this->authorize('create', Booking::class);

        return new BookingFormOptionsResource(BookingFormOptions::fromConfig($config));
    }

    public function quote(QuoteReservationRequest $request, ReservationQuoter $quoter): ReservationQuoteResource
    {
        $this->authorize('create', Booking::class);

        return new ReservationQuoteResource($quoter->quote($request->validated()));
    }

    /**
     * @throws CabinUnavailableException
     */
    #[DocumentedResponse(
        status: 201,
        type: 'array{bookings: list<App\\Http\\Resources\\Rms\\BookingResource>, group: array{id: int, reference: string, name: string, coordinator: array{id: int, name: string}}|null, warnings: list<string>}',
    )]
    public function store(StoreReservationRequest $request, CreateReservation $action): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $created = $action->handle($request->validated(), $actor);

        return (new ReservationCreatedResource($created))->response()->setStatusCode(201);
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        $booking = Booking::query()
            ->withLedgerAggregates()
            ->withGuestSummary()
            ->withChargesSummary()
            ->with([
                'departure.property',
                'departure.itinerary',
                'cabin',
                'contact',
                'group.coordinator',
                'owner',
                'agency',
                'commissionApprovedBy',
                'ratesVersion',
                'bookingRequest',
                'activeClaims',
                'paymentLinks',
                'refundRequest',
            ])
            ->findOrFail($booking->getKey());

        return new BookingResource($booking);
    }

    public function history(Booking $booking): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $booking);

        $entries = $booking->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }

    public function audit(IndexBookingAuditRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', Booking::class);

        $perPage = $request->integer('per_page', 50);
        $from = $request->validated('from');
        $to = $request->validated('to');

        $entries = ChangeHistory::query()
            ->whereIn('event', ['booking.deleted', 'booking.released'])
            ->when(is_string($from) && $from !== '', function (Builder $query) use ($from): void {
                $query->where('created_at', '>=', BusinessTime::dayStartUtc($from));
            })
            ->when(is_string($to) && $to !== '', function (Builder $query) use ($to): void {
                $query->where('created_at', '<=', BusinessTime::dayEndUtc($to));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return BookingAuditResource::collection($entries);
    }

    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\BookingOwnerResource>}',
    )]
    public function owners(): AnonymousResourceCollection
    {
        $this->authorize('viewOwners', Booking::class);

        $owners = User::query()
            ->where('status', UserStatus::Active)
            ->with('role')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::PanelRms))
            ->values();

        return BookingOwnerResource::collection($owners);
    }

    /**
     * @throws CabinUnavailableException
     */
    public function transition(
        TransitionBookingRequest $request,
        Booking $booking,
        TransitionBooking $action,
    ): BookingResource {
        $this->authorize('changeStatus', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, $request->validated(), $actor));
    }

    /**
     * @throws CabinUnavailableException
     */
    public function overdueDecision(
        OverdueDecisionRequest $request,
        Booking $booking,
        DecideOverdue $action,
    ): BookingResource {
        $this->authorize('overdueDecision', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, $request->validated(), $actor));
    }

    public function movePreview(
        PreviewMoveBookingRequest $request,
        Booking $booking,
        MoveBooking $action,
    ): MovePreviewResource {
        $this->authorize('move', $booking);

        return new MovePreviewResource($action->preview($booking, $request->validated()));
    }

    /**
     * @throws CabinUnavailableException
     */
    public function move(MoveBookingRequest $request, Booking $booking, MoveBooking $action): BookingResource
    {
        $this->authorize('move', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, $request->validated(), $actor));
    }

    public function update(UpdateBookingRequest $request, Booking $booking, UpdateBooking $action): BookingResource
    {
        $this->authorize('update', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, $request->validated(), $actor));
    }

    public function destroy(DeleteBookingRequest $request, Booking $booking, DeleteBooking $action): Response
    {
        $this->authorize('delete', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->handle($booking, $request->validated(), $actor);

        return response()->noContent();
    }

    /**
     * @param  Builder<Booking>  $query
     * @return array{overdue_count: int, overdue_amount: int}
     */
    private function overdueKpis(Builder $query): array
    {
        [$cruiseSql, $paid] = Booking::cruiseOutstandingSql();

        $row = $query
            ->overdue()
            ->toBase()
            ->select([])
            ->selectRaw('COUNT(*) as overdue_count, COALESCE(SUM('.$cruiseSql.'), 0) as overdue_amount', $paid)
            ->first();

        return [
            'overdue_count' => (int) ($row->overdue_count ?? 0),
            'overdue_amount' => (int) ($row->overdue_amount ?? 0),
        ];
    }
}
