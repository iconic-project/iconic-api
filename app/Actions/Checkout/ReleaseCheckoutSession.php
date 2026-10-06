<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ReleaseReason;
use App\Models\CheckoutSession;
use App\Services\Inventory\ClaimService;
use App\Support\Inventory\DepartureLocks;

final class ReleaseCheckoutSession extends Action
{
    public function __construct(private readonly ClaimService $claims) {}

    public function handle(CheckoutSession $session): CheckoutSession
    {
        return $this->transaction(function () use ($session): CheckoutSession {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->departure_id !== null) {
                DepartureLocks::lock((int) $session->departure_id);
            }

            if (in_array($session->status, [
                CheckoutSessionStatus::Released,
                CheckoutSessionStatus::Expired,
                CheckoutSessionStatus::Submitted,
            ], true)) {
                return $session;
            }

            $this->claims->release($session, ReleaseReason::Released);
            $session->status = CheckoutSessionStatus::Released;
            $session->save();

            return $session->refresh();
        });
    }
}
