<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Bookings\TransitionBooking;
use App\Actions\Checkout\SettlePaidEngineCheckout;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Exceptions\CabinUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexRequestsRequest;
use App\Http\Requests\Rms\ReleaseRequestRequest;
use App\Http\Resources\Rms\BookingRequestResource;
use App\Http\Resources\Rms\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\RequestQueueRules;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RequestController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\BookingRequestResource>, meta: array{rules: array{near_term_business_hours: int, long_lead_business_days: int, near_term_max_days: int, response_hours: int, business_day_minutes: int, cabin_deposit_pct: int}}}',
    )]
    public function index(IndexRequestsRequest $request, SettlePaidEngineCheckout $settle): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);
        $settle->outstanding();

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $bookings = Booking::query()
            ->select('bookings.*')
            ->join('departures', 'departures.id', '=', 'bookings.departure_id')
            ->join('booking_requests', 'booking_requests.booking_id', '=', 'bookings.id')
            ->with([
                'departure.property',
                'cabin',
                'contact',
                'bookingRequest',
                'claims',
                'agency',
            ])
            ->where('bookings.status', BookingStatus::Requested)
            ->when(
                ! $actor->hasPermission(Permission::BookingsViewAll),
                fn (Builder $query) => $query->where('bookings.owner_id', $actor->id),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query) => $query->whereDate('departures.date', '>=', (string) $request->validated('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query) => $query->whereDate('departures.date', '<=', (string) $request->validated('to')),
            )
            ->orderBy('booking_requests.sla_due_at')
            ->orderBy('bookings.id')
            ->get();

        return BookingRequestResource::collection($bookings)->additional([
            'meta' => [
                'rules' => RequestQueueRules::fromConfig(app(CurrentConfig::class)),
            ],
        ]);
    }

    /**
     * @throws CabinUnavailableException
     */
    public function confirm(Booking $booking, TransitionBooking $action): BookingResource
    {
        $this->authorize('confirm', $booking);

        $actor = request()->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, [
            'to' => BookingStatus::PendingPayment,
        ], $actor));
    }

    /**
     * @throws CabinUnavailableException
     */
    public function release(ReleaseRequestRequest $request, Booking $booking, TransitionBooking $action): BookingResource
    {
        $this->authorize('release', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, [
            'to' => BookingStatus::Released,
            'reason' => $request->validated('reason'),
        ], $actor));
    }
}
