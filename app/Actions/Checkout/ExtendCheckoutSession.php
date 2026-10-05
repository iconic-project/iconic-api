<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\CheckoutSessionStatus;
use App\Exceptions\ConflictException;
use App\Models\CheckoutSession;
use App\Models\RoomNightClaim;
use App\Services\Config\CurrentConfig;
use App\Support\Inventory\DepartureLocks;

final class ExtendCheckoutSession extends Action
{
    public function __construct(private readonly CurrentConfig $config) {}

    public function handle(CheckoutSession $session): CheckoutSession
    {
        return $this->transaction(function () use ($session): CheckoutSession {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            DepartureLocks::lock((int) $session->departure_id);

            if ($session->status !== CheckoutSessionStatus::Holding || $session->expires_at->isPast()) {
                throw new ConflictException('This checkout hold can no longer be extended.');
            }

            if ($session->extended) {
                throw new ConflictException('This checkout hold has already been extended.');
            }

            $minutes = $this->config->businessRules()->holds->webExtensionMinutes;
            $expiresAt = $session->expires_at->copy()->addMinutes($minutes);

            $session->expires_at = $expiresAt;
            $session->extended = true;
            $session->save();

            RoomNightClaim::query()
                ->where('holder_type', $session->getMorphClass())
                ->where('holder_id', $session->id)
                ->whereNull('released_at')
                ->update([
                    'expires_at' => $expiresAt,
                    'updated_at' => now(),
                ]);

            return $session->refresh();
        });
    }
}
