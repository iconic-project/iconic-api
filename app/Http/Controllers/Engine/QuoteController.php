<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\StayQuoteRequest;
use App\Http\Resources\Engine\EngineStayQuoteResource;
use App\Services\Engine\EngineStayQuote;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;

final class QuoteController extends Controller
{
    #[DocumentedResponse(status: 200, type: EngineStayQuoteResource::class)]
    public function __invoke(StayQuoteRequest $request, EngineStayQuote $stays): EngineStayQuoteResource
    {
        /** @var array{check_in: string, check_out: string, rooms: list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string, online_deposit?: bool}>} $validated */
        $validated = $request->validated();

        return new EngineStayQuoteResource($stays->quote($validated));
    }
}
