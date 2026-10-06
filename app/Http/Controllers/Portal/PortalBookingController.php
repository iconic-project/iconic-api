<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Payments\CreatePortalPaymentLink;
use App\Enums\PaymentLinkStatus;
use App\Http\Requests\Portal\CreatePortalPaymentLinkRequest;
use App\Http\Resources\Portal\PortalBookingResource;
use App\Http\Resources\Rms\PaymentLinkResource;
use App\Models\Booking;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PortalBookingController extends PortalController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agency = $this->agency($request);
        $perPage = $request->integer('per_page', 50);

        $bookings = Booking::query()
            ->where('agency_id', $agency->id)
            ->with([
                'roomType',
                'contact',
                'guests',
                'paymentLinks' => fn ($query) => $query->where('status', PaymentLinkStatus::Open),
            ])
            ->withChargesSummary()
            ->withLedgerAggregates()
            ->orderByDesc('id')
            ->paginate($perPage);

        return PortalBookingResource::collection($bookings);
    }

    #[DocumentedResponse(status: 201, type: PaymentLinkResource::class)]
    public function storePaymentLink(
        CreatePortalPaymentLinkRequest $request,
        Booking $booking,
        CreatePortalPaymentLink $action,
    ): JsonResponse {
        $link = $action->handle($booking, $request->validated(), $this->agencyUser($request));

        return (new PaymentLinkResource($link))->response()->setStatusCode(201);
    }
}
