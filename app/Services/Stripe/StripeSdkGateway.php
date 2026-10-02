<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Enums\PaymentKind;
use App\Exceptions\InvalidStripeSignature;
use App\Models\Booking;
use App\Support\Stripe\StripeMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Stripe\Charge;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeSdkGateway implements StripeGateway
{
    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient([
            'api_key' => (string) config('services.stripe.secret'),
            'stripe_version' => '2026-07-29.dahlia',
        ]);
    }

    /**
     * @param  list<array{booking: Booking, amountUsd: int}>  $items
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(array $items, array $metadata, CarbonInterface $expiresAt): CreatedCheckoutSession
    {
        $lineItems = [];

        foreach ($items as $item) {
            $reference = (string) $item['booking']->displayReference();
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => StripeMoney::toCents($item['amountUsd']),
                    'product_data' => [
                        'name' => PaymentKind::Deposit->label().' · '.$reference,
                    ],
                ],
                'quantity' => 1,
            ];
        }

        $engineUrl = rtrim((string) config('iconic.engine_url'), '/');
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'expires_at' => $expiresAt->getTimestamp(),
            'success_url' => $engineUrl.'/book/confirmation?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $engineUrl.'/book/details?cancelled=1',
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
        ]);

        return new CreatedCheckoutSession(
            (string) $session->id,
            (string) $session->url,
            CarbonImmutable::createFromTimestamp((int) $session->expires_at, 'UTC'),
        );
    }

    public function retrieveCheckoutSession(string $stripeId): RetrievedCheckoutSession
    {
        $session = $this->client()->checkout->sessions->retrieve($stripeId);
        $intent = $session->payment_intent;

        return new RetrievedCheckoutSession(
            (string) $session->id,
            (string) $session->status,
            is_string($intent) ? $intent : (is_object($intent) && isset($intent->id) ? (string) $intent->id : null),
        );
    }

    public function createPaymentLink(Booking $booking, PaymentKind $kind, int $amountUsd): CreatedPaymentLink
    {
        $reference = (string) $booking->displayReference();
        $metadata = [
            'booking_id' => (string) $booking->id,
            'booking_reference' => $reference,
            'kind' => $kind->value,
        ];

        $link = $this->client()->paymentLinks->create([
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => StripeMoney::toCents($amountUsd),
                    'product_data' => [
                        'name' => $kind->label().' · '.$reference,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
            'restrictions' => [
                'completed_sessions' => [
                    'limit' => 1,
                ],
            ],
        ]);

        return new CreatedPaymentLink((string) $link->id, (string) $link->url);
    }

    public function deactivatePaymentLink(string $stripeId): void
    {
        $this->client()->paymentLinks->update($stripeId, [
            'active' => false,
        ]);
    }

    public function listCharges(CarbonInterface $fromUtc, CarbonInterface $toUtc): array
    {
        $charges = [];
        $params = [
            'created' => [
                'gte' => $fromUtc->getTimestamp(),
                'lte' => $toUtc->getTimestamp(),
            ],
            'limit' => 100,
        ];

        foreach ($this->client()->charges->all($params)->autoPagingIterator() as $charge) {
            $charges[] = $this->mapCharge($charge);
        }

        return $charges;
    }

    public function retrieveCharge(string $stripeId): StripeCharge
    {
        return $this->mapCharge($this->client()->charges->retrieve($stripeId));
    }

    public function verifyWebhook(string $payload, string $signature): VerifiedStripeEvent
    {
        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException) {
            throw new InvalidStripeSignature;
        }

        /** @var array<string, mixed> $payloadArray */
        $payloadArray = $event->toArray();

        return new VerifiedStripeEvent((string) $event->id, (string) $event->type, $payloadArray);
    }

    private function mapCharge(Charge $charge): StripeCharge
    {
        $intent = $charge->payment_intent;

        return new StripeCharge(
            (string) $charge->id,
            is_string($intent) ? $intent : (is_object($intent) && isset($intent->id) ? (string) $intent->id : null),
            StripeMoney::fromCents((int) $charge->amount),
            CarbonImmutable::createFromTimestamp((int) $charge->created, 'UTC'),
            is_string($charge->description) ? $charge->description : '',
        );
    }
}
