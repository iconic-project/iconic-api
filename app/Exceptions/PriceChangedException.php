<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Resources\Engine\EngineQuoteResource;
use App\Http\Resources\Rms\StayRoomsQuoteResource;
use App\Services\Pricing\ReservationQuote;
use App\Services\Pricing\StayRoomsQuote;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PriceChangedException extends HttpException
{
    public function __construct(
        public ReservationQuote|StayRoomsQuote $quote,
        string $message = 'The price changed. Review the new quote and submit again.',
    ) {
        parent::__construct(409, $message);
    }

    public function render(): JsonResponse
    {
        $request = request();

        $quote = $this->quote instanceof StayRoomsQuote
            ? (new StayRoomsQuoteResource($this->quote))->toArray($request)
            : (new EngineQuoteResource($this->quote))->toArray($request);

        return response()->json([
            'message' => $this->getMessage(),
            'quote' => $quote,
        ], 409);
    }
}
