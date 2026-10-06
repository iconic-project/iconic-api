<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\CheckoutSession;
use App\Services\Pricing\StayRoomsQuote;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CheckoutSession $session
 * @property-read string $token
 * @property-read StayRoomsQuote $quote
 */
class CheckoutCreatedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     token: string,
     *     expires_at: string,
     *     quote: array<string, mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{session: CheckoutSession, token: string, quote: StayRoomsQuote} $payload */
        $payload = $this->resource;

        return [
            'token' => $payload['token'],
            'expires_at' => Iso::utc($payload['session']->expires_at),
            'quote' => (new StayRoomsQuoteResource($payload['quote']))->resolve($request),
        ];
    }
}
