<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Enums\BookingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\EngineQuoteRequest;
use App\Http\Requests\Engine\StayQuoteRequest;
use App\Http\Resources\Engine\EngineQuoteResource;
use App\Http\Resources\Engine\EngineStayQuoteResource;
use App\Models\Departure;
use App\Services\Engine\EngineFeed;
use App\Services\Engine\EngineStayQuote;
use App\Services\Pricing\ReservationQuoter;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

final class QuoteController extends Controller
{
    #[DocumentedResponse(status: 200, type: EngineQuoteResource::class)]
    public function __invoke(
        Request $request,
        EngineFeed $feed,
        ReservationQuoter $quoter,
        EngineStayQuote $stays,
    ): JsonResource {
        if ($request->exists('check_in')) {
            $stay = StayQuoteRequest::createFrom($request);
            $stay->setContainer(app())->setRedirector(app('redirect'));
            $stay->validateResolved();

            /** @var array{check_in: string, check_out: string, rooms: list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>} $validated */
            $validated = $stay->validated();

            return new EngineStayQuoteResource($stays->quote($validated));
        }

        $legacy = EngineQuoteRequest::createFrom($request);
        $legacy->setContainer(app())->setRedirector(app('redirect'));
        $legacy->validateResolved();
        $validated = $legacy->validated();
        $departure = Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->findOrFail((int) $validated['departure_id']);

        abort_unless($feed->isVisible($departure), Response::HTTP_NOT_FOUND);

        $quote = $quoter->quote([
            'departure_id' => $departure->id,
            'type' => BookingType::Cabin->value,
            'cabins' => $legacy->cabinRows(),
            'online_deposit' => (bool) ($validated['online_deposit'] ?? false),
            'promo_code' => $validated['promo_code'] ?? null,
        ], $departure);

        return new EngineQuoteResource($quote);
    }
}
