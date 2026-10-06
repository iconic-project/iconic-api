<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Checkout\CreateStayCheckoutSession;
use App\Actions\Checkout\ExtendCheckoutSession;
use App\Actions\Checkout\OpenStripeCheckout;
use App\Actions\Checkout\ReleaseCheckoutSession;
use App\Actions\Checkout\SettlePaidEngineCheckout;
use App\Actions\Checkout\SubmitEngineCheckout;
use App\Actions\Checkout\SubmitStayCheckout;
use App\Enums\CheckoutPath;
use App\Exceptions\PriceChangedException;
use App\Exceptions\RoomUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\CreateCheckoutRequest;
use App\Http\Requests\Engine\SubmitCheckoutRequest;
use App\Http\Resources\Engine\CheckoutCreatedResource;
use App\Http\Resources\Engine\CheckoutExtendedResource;
use App\Http\Resources\Engine\CheckoutStatusResource;
use App\Http\Resources\Engine\CheckoutSubmittedResource;
use App\Http\Resources\Engine\StayRoomsQuoteResource;
use App\Models\CheckoutSession;
use App\Support\Engine\QuoteToken;
use App\Support\IpHash;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

final class CheckoutController extends Controller
{
    /**
     * @throws RoomUnavailableException
     */
    #[DocumentedResponse(status: 201, type: CheckoutCreatedResource::class)]
    public function store(
        CreateCheckoutRequest $request,
        CreateStayCheckoutSession $stays,
    ): JsonResponse {
        $validated = $request->validated();

        $created = $stays->handle(
            QuoteToken::open((string) $validated['quote_token']),
            [
                'first_name' => (string) $validated['first_name'],
                'last_name' => (string) $validated['last_name'],
                'email' => (string) $validated['email'],
                'phone' => isset($validated['phone']) && is_string($validated['phone']) ? $validated['phone'] : null,
                'preferred_channel' => (string) $validated['preferred_channel'],
                'marketing' => (bool) ($validated['marketing'] ?? false),
                'declarations' => array_values(array_map(
                    static fn (mixed $value): string => (string) $value,
                    is_array($validated['declarations'] ?? null) ? $validated['declarations'] : [],
                )),
                'travel_advisor' => (bool) ($validated['travel_advisor'] ?? false),
                'notes' => isset($validated['notes']) && is_string($validated['notes']) ? $validated['notes'] : null,
            ],
            IpHash::of($request->ip()),
        );

        return response()->json([
            'token' => $created['token'],
            'expires_at' => Iso::utc($created['session']->expires_at),
            'quote' => (new StayRoomsQuoteResource($created['quote']))->resolve($request),
        ], 201);
    }

    #[DocumentedResponse(status: 200, type: CheckoutExtendedResource::class)]
    public function extend(string $token, ExtendCheckoutSession $action): CheckoutExtendedResource
    {
        return new CheckoutExtendedResource($action->handle($this->holding($token)));
    }

    #[DocumentedResponse(status: 200, type: CheckoutStatusResource::class)]
    public function status(string $token, SettlePaidEngineCheckout $settle): CheckoutStatusResource
    {
        $session = CheckoutSession::findByToken($token);

        if (! $session instanceof CheckoutSession) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $settle->handle($session);

        return new CheckoutStatusResource($session->fresh() ?? $session);
    }

    public function destroy(string $token, ReleaseCheckoutSession $action): Response
    {
        $session = CheckoutSession::findByToken($token);

        if (! $session instanceof CheckoutSession) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $action->handle($session);

        return response()->noContent();
    }

    /**
     * @throws RoomUnavailableException
     * @throws PriceChangedException
     */
    #[DocumentedResponse(status: 200, type: CheckoutSubmittedResource::class)]
    public function submit(
        SubmitCheckoutRequest $request,
        string $token,
        SubmitEngineCheckout $action,
        SubmitStayCheckout $stays,
        OpenStripeCheckout $stripe,
    ): CheckoutSubmittedResource|JsonResponse {
        $session = $this->holding($token);
        $result = $session->isStay()
            ? $stays->handle($session, $request->validated(), $request->ip())
            : $action->handle($session, $request->validated(), $request->ip());

        if ($result['path'] !== CheckoutPath::PayDeposit) {
            return new CheckoutSubmittedResource($result);
        }

        try {
            $created = $stripe->handle($session->fresh() ?? $session, $result['bookings']);
        } catch (Throwable $exception) {
            Log::error('Stripe Checkout Session could not be created after engine submit.', [
                'checkout_session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'path' => $result['path']->value,
                'references' => $result['bookings']->map(
                    fn ($booking): string => (string) $booking->displayReference(),
                )->values()->all(),
                'message' => 'Your request is held. Online payment could not be started.',
            ], HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $result['checkout_url'] = $created->url;

        return new CheckoutSubmittedResource($result);
    }

    private function holding(string $token): CheckoutSession
    {
        $session = CheckoutSession::findByToken($token);

        if (! $session instanceof CheckoutSession || $session->isExpired()) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return $session;
    }
}
