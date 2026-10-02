<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Enums\BookingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\EngineQuoteRequest;
use App\Http\Resources\Engine\EngineQuoteResource;
use App\Models\Departure;
use App\Services\Engine\EngineFeed;
use App\Services\Pricing\ReservationQuoter;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Symfony\Component\HttpFoundation\Response;

final class QuoteController extends Controller
{
    #[DocumentedResponse(status: 200, type: EngineQuoteResource::class)]
    public function __invoke(
        EngineQuoteRequest $request,
        EngineFeed $feed,
        ReservationQuoter $quoter,
    ): EngineQuoteResource {
        $validated = $request->validated();
        $departure = Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->findOrFail((int) $validated['departure_id']);

        abort_unless($feed->isVisible($departure), Response::HTTP_NOT_FOUND);

        $quote = $quoter->quote([
            'departure_id' => $departure->id,
            'type' => BookingType::Cabin->value,
            'cabins' => $request->cabinRows(),
            'online_deposit' => (bool) ($validated['online_deposit'] ?? false),
            'promo_code' => $validated['promo_code'] ?? null,
        ], $departure);

        return new EngineQuoteResource($quote);
    }
}
