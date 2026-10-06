<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\CheckPromoRequest;
use App\Http\Resources\Engine\PromoCheckResource;
use App\Services\Engine\EnginePromoCheck;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;

final class PromoCheckController extends Controller
{
    #[DocumentedResponse(status: 200, type: PromoCheckResource::class)]
    public function __invoke(
        CheckPromoRequest $request,
        EnginePromoCheck $checker,
    ): PromoCheckResource {
        $validated = $request->validated();

        return new PromoCheckResource($checker->checkStay(
            (string) $validated['code'],
            (string) $validated['check_in'],
            (string) $validated['check_out'],
            isset($validated['room_type']) ? (string) $validated['room_type'] : null,
            isset($validated['rate_plan']) ? (string) $validated['rate_plan'] : null,
            $request->ip(),
        ));
    }
}
