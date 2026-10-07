<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Enums\PaymentKind;
use App\Exceptions\InvalidStripeSignature;
use App\Models\Booking;
use Carbon\CarbonInterface;

interface StripeGateway
{
    public function createPaymentLink(Booking $booking, PaymentKind $kind, int $amountUsd): CreatedPaymentLink;

    /**
     * @param  list<array{booking: Booking, amountUsd: int}>  $items
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(array $items, array $metadata, CarbonInterface $expiresAt, ?string $customerEmail = null): CreatedCheckoutSession;

    public function retrieveCheckoutSession(string $stripeId): RetrievedCheckoutSession;

    public function deactivatePaymentLink(string $stripeId): void;

    /**
     * @return list<StripeCharge>
     */
    public function listCharges(CarbonInterface $fromUtc, CarbonInterface $toUtc): array;

    public function retrieveCharge(string $stripeId): StripeCharge;

    /**
     * @throws InvalidStripeSignature
     */
    public function verifyWebhook(string $payload, string $signature): VerifiedStripeEvent;
}
