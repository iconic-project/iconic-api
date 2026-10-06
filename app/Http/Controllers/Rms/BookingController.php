<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Bookings\CheckInBooking;
use App\Actions\Bookings\CheckOutBooking;
use App\Actions\Bookings\CreateReservation;
use App\Actions\Bookings\CreateStayReservation;
use App\Actions\Bookings\DecideOverdue;
use App\Actions\Bookings\DeleteBooking;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\ModifyStay;
use App\Actions\Bookings\MoveBooking;
use App\Actions\Bookings\MoveRoom;
use App\Actions\Bookings\TransitionBooking;
use App\Actions\Bookings\UndoCheckIn;
use App\Actions\Bookings\UpdateBooking;
use App\Enums\BookingSegment;
use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\RoomUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\CheckInBookingRequest;
use App\Http\Requests\Rms\CheckOutBookingRequest;
use App\Http\Requests\Rms\DeleteBookingRequest;
use App\Http\Requests\Rms\IndexBookingAuditRequest;
use App\Http\Requests\Rms\IndexBookingsRequest;
use App\Http\Requests\Rms\MarkNoShowRequest;
use App\Http\Requests\Rms\ModifyStayRequest;
use App\Http\Requests\Rms\MoveBookingRequest;
use App\Http\Requests\Rms\OverdueDecisionRequest;
use App\Http\Requests\Rms\PreviewModifyStayRequest;
use App\Http\Requests\Rms\PreviewMoveBookingRequest;
use App\Http\Requests\Rms\QuoteReservationRequest;
use App\Http\Requests\Rms\StoreReservationRequest;
use App\Http\Requests\Rms\TransitionBookingRequest;
use App\Http\Requests\Rms\UndoCheckInRequest;
use App\Http\Requests\Rms\UpdateBookingRequest;
use App\Http\Resources\Rms\BookingAuditResource;
use App\Http\Resources\Rms\BookingFormOptionsResource;
use App\Http\Resources\Rms\BookingOwnerResource;
use App\Http\Resources\Rms\BookingResource;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\ModifyStayPreviewResource;
use App\Http\Resources\Rms\MovePreviewResource;
use App\Http\Resources\Rms\ReservationCreatedResource;
use App\Http\Resources\Rms\StayRoomsQuoteResource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\StayQuoter;
use App\Support\Bookings\BookingFormOptions;
use App\Support\BusinessTime;
use App\Support\Stays\StayDates;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->visibleTo($actor)
            ->arrivingBetween(
                is_string($request->validated('from')) ? $request->validated('from') : null,
                is_string($request->validated('to')) ? $request->validated('to') : null,
            )
            ->arrivingBetween(
                is_string($request->validated('arriving_from')) ? $request->validated('arriving_from') : null,
                is_string($request->validated('arriving_to')) ? $request->validated('arriving_to') : null,
            )
            ->checkingOutBetween(
                is_string($request->validated('departing_from')) ? $request->validated('departing_from') : null,
                is_string($request->validated('departing_to')) ? $request->validated('departing_to') : null,
            )
            ->when(
                is_string($request->validated('in_house_on')) && $request->validated('in_house_on') !== '',
                fn (Builder $query) => $query->inHouseOn((string) $request->validated('in_house_on')),
            )
            ->when(
                $request->filled('owner_id'),
                fn (Builder $query) => $query->where('bookings.owner_id', $request->validated('owner_id')),
            )
            ->when(
                $request->filled('channel'),
                fn (Builder $query) => $query->where('bookings.main_channel', $request->validated('channel')),
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
                'room.roomType',
                'roomType',
                'property',
                'contact',
                'group.coordinator',
                'owner',
                'agency',
                'commissionApprovedBy',
                'ratesVersion',
                'bookingRequest',
                'activeClaims',
            ])
            ->orderBy('bookings.check_in')
            ->orderBy('bookings.reference')
            ->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'meta' => ['kpis' => $kpis],
        ]);
    }

    public function formOptions(Request $request, CurrentConfig $config): BookingFormOptionsResource
    {
        $this->authorize('create', Booking::class);

        return new BookingFormOptionsResource(BookingFormOptions::fromConfig(
            $config,
            BookingFormOptions::stayFromQuery($request),
        ));
    }

    public function quote(
        QuoteReservationRequest $request,
        StayQuoter $stayQuoter,
    ): StayRoomsQuoteResource {
        $this->authorize('create', Booking::class);

        /** @var array{check_in: string, check_out: string, main_channel?: string, rooms: list<array{room_type: string, adults: int, child_ages?: list<int>, rate_plan?: string|null, promo?: string|null, online_deposit?: bool}>} $validated */
        $validated = $request->validated();
        $channel = isset($validated['main_channel'])
            ? MainChannel::from($validated['main_channel'])->segment()->value
            : BookingSegment::D2C->value;

        foreach ($validated['rooms'] as $index => $room) {
            $validated['rooms'][$index]['channel'] = $channel;
        }

        return new StayRoomsQuoteResource($stayQuoter->quoteRooms(
            StayDates::of($validated['check_in'], $validated['check_out']),
            $validated['rooms'],
        ));
    }

    /**
     * @throws RoomUnavailableException
     */
    #[DocumentedResponse(
        status: 201,
        type: 'array{bookings: list<App\\Http\\Resources\\Rms\\BookingResource>, group: array{id: int, reference: string, name: string, coordinator: array{id: int, name: string}}|null, warnings: list<string>}',
    )]
    public function store(
        StoreReservationRequest $request,
        CreateReservation $action,
        CreateStayReservation $stays,
    ): JsonResponse {
        $this->authorize('create', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $created = $request->filled('check_in')
            ? $stays->handle($request->validated(), $actor)
            : $action->handle($request->validated(), $actor);

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
                'room.roomType',
                'roomType',
                'property',
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

    public function checkIn(CheckInBookingRequest $request, Booking $booking, CheckInBooking $action): BookingResource
    {
        $this->authorize('frontDesk', $booking);

        return new BookingResource($action->handle($booking, $request->validated(), $this->actor($request)));
    }

    public function checkOut(CheckOutBookingRequest $request, Booking $booking, CheckOutBooking $action): BookingResource
    {
        $this->authorize('frontDesk', $booking);

        return new BookingResource($action->handle($booking, $request->validated(), $this->actor($request)));
    }

    public function noShow(MarkNoShowRequest $request, Booking $booking, MarkNoShow $action): BookingResource
    {
        $this->authorize('frontDesk', $booking);

        return new BookingResource($action->handle($booking, $request->validated(), $this->actor($request)));
    }

    public function undoCheckIn(UndoCheckInRequest $request, Booking $booking, UndoCheckIn $action): BookingResource
    {
        $this->authorize('undoCheckIn', $booking);

        return new BookingResource($action->handle($booking, $request->validated(), $this->actor($request)));
    }

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
     * @throws RoomUnavailableException
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

    public function modifyPreview(
        PreviewModifyStayRequest $request,
        Booking $booking,
        ModifyStay $action,
    ): ModifyStayPreviewResource {
        $this->authorize('move', $booking);

        return new ModifyStayPreviewResource($action->preview($booking, $request->validated(), $this->actor($request)));
    }

    public function modify(ModifyStayRequest $request, Booking $booking, ModifyStay $action): BookingResource
    {
        $this->authorize('move', $booking);

        return new BookingResource($action->handle($booking, $request->validated(), $this->actor($request)));
    }

    public function movePreview(
        PreviewMoveBookingRequest $request,
        Booking $booking,
        MoveBooking $action,
        MoveRoom $moveRoom,
    ): MovePreviewResource|ModifyStayPreviewResource {
        $this->authorize('move', $booking);

        if ($request->filled('room_id')) {
            return new ModifyStayPreviewResource($moveRoom->preview($booking, $request->validated(), $this->actor($request)));
        }

        return new MovePreviewResource($action->preview($booking, $request->validated()));
    }

    /**
     * @throws RoomUnavailableException
     */
    public function move(MoveBookingRequest $request, Booking $booking, MoveBooking $action, MoveRoom $moveRoom): BookingResource
    {
        $this->authorize('move', $booking);
        $actor = $this->actor($request);

        if ($request->filled('room_id')) {
            return new BookingResource($moveRoom->handle($booking, $request->validated(), $actor));
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

    private function actor(FormRequest $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    /**
     * @param  Builder<Booking>  $query
     * @return array{overdue_count: int, overdue_amount: int}
     */
    private function overdueKpis(Builder $query): array
    {
        [$staySql, $paid] = Booking::stayOutstandingSql();

        $row = $query
            ->overdue()
            ->toBase()
            ->select([])
            ->selectRaw('COUNT(*) as overdue_count, COALESCE(SUM('.$staySql.'), 0) as overdue_amount', $paid)
            ->first();

        return [
            'overdue_count' => (int) ($row->overdue_count ?? 0),
            'overdue_amount' => (int) ($row->overdue_amount ?? 0),
        ];
    }
}
