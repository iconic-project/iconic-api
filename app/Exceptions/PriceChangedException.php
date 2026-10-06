<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Resources\Rms\StayRoomsQuoteResource;
use App\Services\Pricing\StayRoomsQuote;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PriceChangedException extends HttpException
{
    public function __construct(
        public StayRoomsQuote $quote,
        string $message = 'The price changed. Review the new quote and submit again.',
    ) {
        parent::__construct(409, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'quote' => (new StayRoomsQuoteResource($this->quote))->toArray(request()),
        ], 409);
    }
}
