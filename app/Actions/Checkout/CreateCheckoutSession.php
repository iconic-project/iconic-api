<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\BookingType;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Exceptions\RoomUnavailableException;
use App\Models\CheckoutSession;
use App\Models\Departure;
use App\Models\Room;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\ReservationQuote;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Inventory\CabinConflict;
use App\Support\Inventory\DepartureLocks;
use Illuminate\Support\Collection;

final class CreateCheckoutSession extends Action
{
    public function __construct(
        private readonly ClaimService $claims,
        private readonly CurrentConfig $config,
        private readonly ReservationQuoter $quoter,
    ) {}

    /**
     * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
     * @return array{session: CheckoutSession, token: string, quote: ReservationQuote}
     */
    public function handle(Departure $departure, array $cabins, string $ipHash): array
    {
        return $this->transaction(function () use ($departure, $cabins, $ipHash): array {
            $departure = DepartureLocks::lock((int) $departure->id);
            $departure->loadMissing(['property.cabins', 'itinerary']);

            $this->releaseOldestIfCapped($ipHash);

            $token = bin2hex(random_bytes(32));
            $minutes = $this->config->businessRules()->holds->webMinutes;
            $expiresAt = now()->addMinutes($minutes);

            $session = CheckoutSession::query()->create([
                'token_hash' => CheckoutSession::hashToken($token),
                'departure_id' => $departure->id,
                'cabins' => $cabins,
                'status' => CheckoutSessionStatus::Holding,
                'expires_at' => $expiresAt,
                'extended' => false,
                'ip_hash' => $ipHash,
            ]);

            $claimed = $this->cabinsFor($departure, $cabins);

            try {
                DepartureLocks::lock((int) $departure->id);
                $this->claims->claim(
                    $departure->stayDates(),
                    $claimed,
                    $session,
                    ClaimKind::Hold,
                    HoldType::Web,
                    $expiresAt,
                );
            } catch (RoomUnavailableException) {
                throw CabinConflict::exception($departure->stayDates(), $claimed, $session);
            }

            $quote = $this->quoter->quote([
                'departure_id' => $departure->id,
                'type' => BookingType::Cabin->value,
                'cabins' => $cabins,
                'online_deposit' => false,
            ], $departure);

            return [
                'session' => $session->refresh(),
                'token' => $token,
                'quote' => $quote,
            ];
        });
    }

    private function releaseOldestIfCapped(string $ipHash): void
    {
        $holding = CheckoutSession::query()
            ->where('ip_hash', $ipHash)
            ->where('status', CheckoutSessionStatus::Holding)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($holding->count() < 2) {
            return;
        }

        $oldest = $holding->first();

        if (! $oldest instanceof CheckoutSession) {
            return;
        }

        $this->claims->release($oldest, ReleaseReason::Released);
        $oldest->status = CheckoutSessionStatus::Released;
        $oldest->save();
    }

    /**
     * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
     * @return Collection<int, Room>
     */
    private function cabinsFor(Departure $departure, array $cabins): Collection
    {
        $codes = array_map(fn (array $row): string => $row['cabin_code'], $cabins);

        return $departure->property->cabins
            ->filter(fn (Room $cabin): bool => in_array($cabin->code, $codes, true))
            ->sortBy('sort')
            ->values();
    }
}
