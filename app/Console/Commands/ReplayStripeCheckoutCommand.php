<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payments\SettleGatewayPayment;
use App\Enums\PaymentKind;
use App\Enums\PaymentLinkStatus;
use App\Http\Controllers\StripeWebhookController;
use App\Jobs\ProcessStripeEvent;
use App\Models\Booking;
use App\Models\CheckoutSession;
use App\Models\PaymentLink;
use App\Models\StripeEvent;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Support\Stripe\StripeMoney;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class ReplayStripeCheckoutCommand extends Command
{
    protected $signature = 'iconic:replay-stripe-checkout
        {reference : Booking or request reference}
        {--expired : Replay checkout.session.expired for an engine Checkout Session}';

    protected $description = 'Replay checkout.session.completed (or --expired) for a payment link or engine session (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('iconic:replay-stripe-checkout only runs in local and testing.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $booking = Booking::query()
            ->where('reference', $reference)
            ->orWhere('request_reference', $reference)
            ->first();

        if (! $booking instanceof Booking) {
            $this->error('No booking with reference '.$reference.'.');

            return self::FAILURE;
        }

        $session = $booking->checkoutSession;

        if ($this->option('expired')) {
            if (! $this->isEngineSession($session)) {
                $this->error('No engine Checkout Session on '.$reference.' to expire.');

                return self::FAILURE;
            }

            $this->postSignedEvent($this->engineExpiredEvent($session));
            $this->info('Replayed checkout.session.expired twice for '.$reference.' ('.$session->stripe_checkout_session_id.').');

            return self::SUCCESS;
        }

        if ($this->isEngineSession($session)) {
            $this->postSignedEvent($this->engineCompletedEvent($session));
            $this->info('Replayed checkout.session.completed twice for '.$reference.' ('.$session->stripe_checkout_session_id.').');

            return self::SUCCESS;
        }

        $link = PaymentLink::query()
            ->where('booking_id', $booking->id)
            ->where('status', PaymentLinkStatus::Open)
            ->orderBy('id')
            ->first();

        if (! $link instanceof PaymentLink) {
            $this->error('No OPEN payment link or engine Checkout Session on '.$reference.'.');

            return self::FAILURE;
        }

        $this->postSignedEvent($this->paymentLinkCompletedEvent($booking, $link));
        $this->info('Replayed checkout.session.completed twice for '.$reference.' ('.$link->stripe_id.').');

        return self::SUCCESS;
    }

    private function isEngineSession(?CheckoutSession $session): bool
    {
        return $session instanceof CheckoutSession
            && is_string($session->stripe_checkout_session_id)
            && $session->stripe_checkout_session_id !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function engineCompletedEvent(CheckoutSession $session): array
    {
        $session->loadMissing('bookings');
        $metadata = [
            'checkout_session_id' => (string) $session->id,
            'kind' => PaymentKind::Deposit->value,
        ];
        $amountUsd = 0;

        foreach ($session->bookings as $booking) {
            $deposit = $booking->depositAmount();
            $amountUsd += $deposit;
            $metadata['deposit_'.$booking->id] = (string) $deposit;
        }

        return [
            'id' => 'evt_replay_engine_'.$session->id,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $session->stripe_checkout_session_id,
                    'payment_intent' => 'pi_replay_engine_'.$session->id,
                    'amount_total' => StripeMoney::toCents($amountUsd),
                    'metadata' => $metadata,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function engineExpiredEvent(CheckoutSession $session): array
    {
        return [
            'id' => 'evt_replay_engine_expired_'.$session->id,
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => $session->stripe_checkout_session_id,
                    'metadata' => [
                        'checkout_session_id' => (string) $session->id,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentLinkCompletedEvent(Booking $booking, PaymentLink $link): array
    {
        return [
            'id' => 'evt_replay_'.$link->id,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_replay_'.$link->id,
                    'payment_intent' => 'pi_replay_'.$link->id,
                    'payment_link' => $link->stripe_id,
                    'amount_total' => StripeMoney::toCents($link->amount),
                    'metadata' => [
                        'booking_id' => (string) $booking->id,
                        'booking_reference' => (string) $booking->displayReference(),
                        'kind' => $link->kind->value,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postSignedEvent(array $event): void
    {
        $eventId = (string) $event['id'];
        $signed = FakeStripeGateway::signedEvent($event);
        $request = Request::create(
            '/api/stripe/webhook',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
            ],
            content: $signed['payload'],
        );

        $controller = app(StripeWebhookController::class);
        $gateway = app(StripeGateway::class);
        $controller($request, $gateway);
        $controller($request, $gateway);

        $stored = StripeEvent::query()->where('stripe_event_id', $eventId)->first();

        if ($stored instanceof StripeEvent && $stored->processed_at === null) {
            (new ProcessStripeEvent($eventId))->handle(app(SettleGatewayPayment::class));
        }
    }
}
