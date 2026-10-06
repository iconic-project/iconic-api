<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\CheckPromoRequest;
use App\Http\Resources\Engine\PromoCheckResource;
use App\Models\Departure;
use App\Services\Engine\EngineFeed;
use App\Services\Engine\EnginePromoCheck;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Symfony\Component\HttpFoundation\Response;

final class PromoCheckController extends Controller
{
    #[DocumentedResponse(status: 200, type: PromoCheckResource::class)]
    public function __invoke(
        CheckPromoRequest $request,
        EngineFeed $feed,
        EnginePromoCheck $checker,
    ): PromoCheckResource {
        if ($request->filled('check_in')) {
            $validated = $request->validated();

            return new PromoCheckResource($checker->checkStay(
                (string) $validated['code'],
                (string) $validated['check_in'],
                isset($validated['room_type']) ? (string) $validated['room_type'] : null,
                $request->ip(),
            ));
        }

        $departure = Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->findOrFail((int) $request->validated('departure_id'));

        abort_unless($feed->isVisible($departure), Response::HTTP_NOT_FOUND);

        $validated = $request->validated();
        $validated['cabins'] = $request->cabinRows();

        return new PromoCheckResource($checker->check(
            $validated,
            $departure,
            $request->ip(),
        ));
    }
}
