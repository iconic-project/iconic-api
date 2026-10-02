<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundRequestStatus;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\StripeEvent;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

test('a clean ledger and a two-booking checkout stay silent until the event amount is tampered', function (): void {
    Artisan::call('iconic:ledger-check');
    expect(Alert::query()->where('kind', AlertKind::LedgerDrift)->count())->toBe(0);

    $first = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-7001',
    ]);
    $second = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-7002',
    ]);
    $payments = collect([
        stripePayment($first, 1000, 'pi_group#'.$first->id),
        stripePayment($second, 2000, 'pi_group#'.$second->id),
    ]);
    $event = checkoutEvent('pi_group', 300000);

    Artisan::call('iconic:ledger-check');
    expect(Alert::query()->where('kind', AlertKind::LedgerDrift)->count())->toBe(0);

    $before = Payment::query()->orderBy('id')->pluck('amount', 'id')->all();
    $payload = $event->payload;
    $payload['data']['object']['amount_total'] = 100;
    $event->payload = $payload;
    $event->save();

    Artisan::call('iconic:ledger-check');

    $alert = Alert::query()->where('kind', AlertKind::LedgerDrift)->first();
    expect(Alert::query()->where('kind', AlertKind::LedgerDrift)->count())->toBe(1)
        ->and($alert?->sentence)->toContain('ANK-2026-7001')
        ->and($alert?->sentence)->toContain('ANK-2026-7002')
        ->and($alert?->sentence)->toContain('stripe payment')
        ->and(Payment::query()->count())->toBe($payments->count())
        ->and(Payment::query()->orderBy('id')->pluck('amount', 'id')->all())->toBe($before);

    $payload['data']['object']['amount_total'] = 300000;
    $event->payload = $payload;
    $event->save();

    Artisan::call('iconic:ledger-check');

    expect($alert?->fresh()?->resolved_at)->not->toBeNull()
        ->and($alert?->fresh()?->resolution)->toBe('the next ledger run found no difference')
        ->and(Payment::query()->orderBy('id')->pluck('amount', 'id')->all())->toBe($before);
});

test('two partial refunds that sum to the latest amount refunded stay silent', function (): void {
    $booking = Booking::factory()->create([
        'status' => BookingStatus::Cancelled,
        'reference' => 'ANK-2026-7003',
    ]);
    $first = stripePayment($booking, -400, 're_partial_1', PaymentKind::Refund, PaymentStatus::Refunded);
    $second = stripePayment($booking, -600, 're_partial_2', PaymentKind::Refund, PaymentStatus::Refunded);

    foreach ([$first, $second] as $payment) {
        RefundRequest::factory()->create([
            'booking_id' => $booking->id,
            'status' => RefundRequestStatus::Executed,
            'executed_payment_id' => $payment->id,
            'refund_due' => abs($payment->amount),
        ]);
    }

    StripeEvent::query()->create([
        'stripe_event_id' => 'evt_refund_partials',
        'type' => 'charge.refunded',
        'payload' => [
            'data' => [
                'object' => [
                    'id' => 'ch_partials',
                    'amount_refunded' => 100000,
                    'currency' => 'usd',
                    'refunds' => [
                        'data' => [
                            ['id' => 're_partial_1'],
                            ['id' => 're_partial_2'],
                        ],
                    ],
                ],
            ],
        ],
        'received_at' => now(),
    ]);

    Artisan::call('iconic:ledger-check');

    expect(Alert::query()->where('kind', AlertKind::LedgerDrift)->count())->toBe(0);
});

function stripePayment(
    Booking $booking,
    int $amount,
    string $gatewayId,
    PaymentKind $kind = PaymentKind::Deposit,
    PaymentStatus $status = PaymentStatus::Settled,
): Payment {
    return Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => $kind,
        'method' => PaymentMethod::StripeLink,
        'status' => $status,
        'amount' => $amount,
        'gateway_id' => $gatewayId,
        'reference' => $booking->reference.'-'.$kind->letter().$gatewayId,
    ]);
}

function checkoutEvent(string $paymentIntent, int $amountTotal): StripeEvent
{
    return StripeEvent::query()->create([
        'stripe_event_id' => 'evt_'.$paymentIntent,
        'type' => 'checkout.session.completed',
        'payload' => [
            'data' => [
                'object' => [
                    'id' => 'cs_'.$paymentIntent,
                    'payment_intent' => $paymentIntent,
                    'amount_total' => $amountTotal,
                    'currency' => 'usd',
                ],
            ],
        ],
        'received_at' => now(),
    ]);
}
