<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\CheckoutSession;
use App\Services\Stripe\CreatedCheckoutSession;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class OpenStripeCheckout extends Action
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function handle(CheckoutSession $session, Collection $bookings): CreatedCheckoutSession
    {
        $items = [];
        $metadata = [
            'checkout_session_id' => (string) $session->id,
            'kind' => PaymentKind::Deposit->value,
            'booking_ids' => $bookings->pluck('id')->implode(','),
        ];

        foreach ($bookings as $booking) {
            $amount = $booking->depositAmount();
            $items[] = [
                'booking' => $booking,
                'amountUsd' => $amount,
            ];
            $metadata['deposit_'.$booking->id] = (string) $amount;
        }

        $expiresAt = now()->addMinutes(30);
        $created = $this->stripe->createCheckoutSession($items, $metadata, $expiresAt, $this->guestEmail($session));

        $session->stripe_checkout_session_id = $created->stripeId;
        $session->stripe_expires_at = Carbon::createFromImmutable($created->expiresAt);
        $session->save();

        return $created;
    }

    private function guestEmail(CheckoutSession $session): ?string
    {
        $guest = $session->guest;
        $email = is_array($guest) ? ($guest['email'] ?? null) : null;

        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? null : $email;
    }
}
