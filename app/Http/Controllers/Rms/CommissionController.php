<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Commissions\DecideCommissionCap;
use App\Actions\Commissions\RecordCommissionPayout;
use App\Enums\CommissionAccrualStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\CommissionApprovalRequest;
use App\Http\Requests\Rms\IndexCommissionsRequest;
use App\Http\Requests\Rms\StoreCommissionPayoutRequest;
use App\Http\Resources\Rms\BookingResource;
use App\Http\Resources\Rms\CommissionResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CommissionController extends Controller
{
    public function index(IndexCommissionsRequest $request, CurrentConfig $config): AnonymousResourceCollection
    {
        $this->authorize('viewCommissions', Booking::class);

        $rules = $config->businessRules();
        $status = $request->filled('status')
            ? CommissionAccrualStatus::from((string) $request->validated('status'))
            : null;

        $bookings = Booking::query()
            ->whereNotNull('agency_id')
            ->with(['agency', 'room.roomType', 'property', 'commissionPayout'])
            ->when(
                $request->filled('from') || $request->filled('to'),
                function (Builder $query) use ($request): void {
                    $query
                        ->when($request->filled('from'), fn (Builder $inner) => $inner->whereDate('bookings.check_in', '>=', (string) $request->validated('from')))
                        ->when($request->filled('to'), fn (Builder $inner) => $inner->whereDate('bookings.check_in', '<=', (string) $request->validated('to')));
                },
            )
            ->orderByDesc('id')
            ->get()
            ->filter(function (Booking $booking) use ($rules, $status): bool {
                return $status === null || Accrual::status($booking, $rules) === $status;
            })
            ->values();

        return CommissionResource::collection($bookings);
    }

    public function decide(
        CommissionApprovalRequest $request,
        Booking $booking,
        DecideCommissionCap $action,
    ): BookingResource {
        $this->authorize('commissionApproval', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new BookingResource($action->handle($booking, $request->validated(), $actor));
    }

    public function payout(
        StoreCommissionPayoutRequest $request,
        Booking $booking,
        RecordCommissionPayout $action,
    ): JsonResponse {
        $this->authorize('recordPayout', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        /** @var array{amount: int, paid_on: string, bank_reference: string} $data */
        $data = $request->validated();
        $data['amount'] = (int) $data['amount'];

        $updated = $action->handle($booking, $data, $actor);

        return (new CommissionResource($updated))->response()->setStatusCode(201);
    }
}
