<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Enums\PaymentKind;
use App\Exceptions\InvalidStripeSignature;
use App\Models\Booking;
use App\Support\BusinessTime;
use App\Support\Stripe\StripeWebhookSignature;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, CreatedPaymentLink> */
    public array $links = [];

    /** @var array<string, array{session: CreatedCheckoutSession, status: string, payment_intent: string|null, metadata: array<string, string>}> */
    public array $checkoutSessions = [];

    public int $checkoutSequence = 0;

    /** @var array<string, bool> */
    public array $active = [];

    /** @var list<StripeCharge> */
    public array $charges = [];

    public int $linkSequence = 0;

    public bool $includeFileFixture = false;

    public bool $failCheckout = false;

    public int $retrieveCheckoutCalls = 0;

    public ?string $lastSuccessUrl = null;

    public ?string $lastCancelUrl = null;

    public ?string $lastCustomerEmail = null;

    public static function fixturePath(): string
    {
        return database_path('fixtures/stripe-charges.json');
    }

    /**
     * @param  list<array{booking: Booking, amountUsd: int}>  $items
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(array $items, array $metadata, CarbonInterface $expiresAt, ?string $customerEmail = null): CreatedCheckoutSession
    {
        if ($this->failCheckout) {
            throw new RuntimeException('Stripe Checkout Session could not be created.');
        }

        $this->checkoutSequence++;
        $id = 'cs_test_'.str_pad((string) $this->checkoutSequence, 3, '0', STR_PAD_LEFT);
        $expires = CarbonImmutable::instance($expiresAt)->utc();
        $engineUrl = rtrim((string) config('iconic.engine_url'), '/');
        $this->lastSuccessUrl = $engineUrl.'/book/confirmation?session_id={CHECKOUT_SESSION_ID}';
        $this->lastCancelUrl = $engineUrl.'/book/details?cancelled=1';
        $this->lastCustomerEmail = $customerEmail;
        $created = new CreatedCheckoutSession($id, 'https://checkout.stripe.com/c/pay/'.$id, $expires);
        $this->checkoutSessions[$id] = [
            'session' => $created,
            'status' => 'open',
            'payment_intent' => 'pi_'.$id,
            'metadata' => $metadata,
        ];

        return $created;
    }

    public function retrieveCheckoutSession(string $stripeId): RetrievedCheckoutSession
    {
        $this->retrieveCheckoutCalls++;
        $row = $this->checkoutSessions[$stripeId] ?? null;

        if ($row === null) {
            throw new InvalidArgumentException('Unknown Stripe checkout session '.$stripeId);
        }

        return new RetrievedCheckoutSession($stripeId, $row['status'], $row['payment_intent']);
    }

    public function setCheckoutSessionStatus(string $stripeId, string $status, ?string $paymentIntent = null): void
    {
        if (! isset($this->checkoutSessions[$stripeId])) {
            throw new InvalidArgumentException('Unknown Stripe checkout session '.$stripeId);
        }

        $this->checkoutSessions[$stripeId]['status'] = $status;

        if ($paymentIntent !== null) {
            $this->checkoutSessions[$stripeId]['payment_intent'] = $paymentIntent;
        }
    }

    public function createPaymentLink(Booking $booking, PaymentKind $kind, int $amountUsd): CreatedPaymentLink
    {
        $this->linkSequence++;
        $id = 'plink_test_'.str_pad((string) $this->linkSequence, 3, '0', STR_PAD_LEFT);
        $link = new CreatedPaymentLink($id, 'https://buy.stripe.com/test/'.$id);
        $this->links[$id] = $link;
        $this->active[$id] = true;

        return $link;
    }

    public function deactivatePaymentLink(string $stripeId): void
    {
        if (! isset($this->links[$stripeId])) {
            throw new RuntimeException('Unknown payment link '.$stripeId);
        }

        $this->active[$stripeId] = false;
    }

    public function listCharges(CarbonInterface $fromUtc, CarbonInterface $toUtc): array
    {
        $from = $fromUtc->getTimestamp();
        $to = $toUtc->getTimestamp();

        return array_values(array_filter(
            $this->allCharges(),
            fn (StripeCharge $charge): bool => $charge->createdAt->getTimestamp() >= $from
                && $charge->createdAt->getTimestamp() <= $to,
        ));
    }

    public function retrieveCharge(string $stripeId): StripeCharge
    {
        foreach ($this->allCharges() as $charge) {
            if ($charge->id === $stripeId) {
                return $charge;
            }
        }

        throw new InvalidArgumentException('Unknown Stripe charge '.$stripeId);
    }

    /**
     * @return list<StripeCharge>
     */
    public function allCharges(): array
    {
        return [...$this->charges, ...$this->chargesFromFile()];
    }

    /**
     * Dates are computed at read time from `created_days_ago` so they stay
     * inside the current Galápagos month after a clock travel.
     *
     * @return list<StripeCharge>
     */
    public function chargesFromFile(): array
    {
        if (! $this->includeFileFixture) {
            return [];
        }

        $path = self::fixturePath();

        if (! is_file($path)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $now = BusinessTime::now();
        $charges = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $intent = $row['payment_intent'] ?? null;
            $amount = $row['amount'] ?? null;
            $daysAgo = $row['created_days_ago'] ?? null;
            $description = $row['description'] ?? '';

            if (! is_string($id) || $id === '' || ! is_string($intent) || $intent === '' || ! is_int($amount)) {
                continue;
            }

            if (! is_int($daysAgo) && ! is_numeric($daysAgo)) {
                continue;
            }

            $charges[] = self::charge(
                $id,
                $intent,
                $amount,
                $now->subDays((int) $daysAgo),
                is_string($description) ? $description : '',
            );
        }

        return $charges;
    }

    public function verifyWebhook(string $payload, string $signature): VerifiedStripeEvent
    {
        StripeWebhookSignature::verify(
            $payload,
            $signature,
            (string) config('services.stripe.webhook_secret'),
        );

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidStripeSignature;
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            throw new InvalidStripeSignature;
        }

        return new VerifiedStripeEvent($id, $type, $decoded);
    }

    public function seedCharge(StripeCharge $charge): void
    {
        $this->charges[] = $charge;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{payload: string, signature: string}
     */
    public static function signedEvent(array $event, ?int $timestamp = null): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = StripeWebhookSignature::sign(
            $payload,
            (string) config('services.stripe.webhook_secret'),
            $timestamp ?? time(),
        );

        return [
            'payload' => $payload,
            'signature' => $signature,
        ];
    }

    public static function charge(
        string $id,
        string $paymentIntentId,
        int $amountUsd,
        CarbonImmutable $createdAt,
        string $description = '',
    ): StripeCharge {
        return new StripeCharge($id, $paymentIntentId, $amountUsd, $createdAt, $description);
    }
}
