<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\CheckoutSessionStatus;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Enums\RoomTypeStatus;
use App\Exceptions\PriceChangedException;
use App\Exceptions\RoomUnavailableException;
use App\Models\CheckoutSession;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Inventory\NightAvailability;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayRoomsQuote;
use App\Support\Stays\StayDates;
use Illuminate\Validation\ValidationException;

/**
 * Holds every room on a signed stay quote. One session, N rooms.
 */
final class CreateStayCheckoutSession extends Action
{
    public function __construct(
        private readonly ClaimService $claims,
        private readonly CurrentConfig $config,
        private readonly StayQuoter $quoter,
        private readonly NightAvailability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $token
     * @param  array{first_name: string, last_name: string, email: string, phone: string|null, preferred_channel: string, marketing: bool, declarations: list<string>, travel_advisor: bool, notes: string|null}  $guest
     * @return array{session: CheckoutSession, token: string, quote: StayRoomsQuote}
     */
    public function handle(array $token, array $guest, string $ipHash): array
    {
        $stay = $this->stay($token);
        $lines = $this->lines($token);
        $quote = $this->quoter->quoteRooms($stay, $lines);

        if ($quote->total === null || $quote->deposit === null || $quote->totalIncludingChargedTaxes === null) {
            throw ValidationException::withMessages([
                'rooms' => ['A price could not be calculated.'],
            ]);
        }

        $version = (int) $this->config->version(ConfigKind::Rates)->id;
        $drifted = $quote->total !== (int) ($token['total'] ?? -1)
            || $quote->deposit !== (int) ($token['deposit'] ?? -1)
            || $quote->totalIncludingChargedTaxes !== (int) ($token['total_including_charged_taxes'] ?? -1)
            || $version !== (int) ($token['rates_version_id'] ?? 0);

        if ($drifted) {
            throw new PriceChangedException($quote);
        }

        return $this->transaction(function () use ($stay, $lines, $quote, $guest, $ipHash): array {
            $this->releaseOldestIfCapped($ipHash);
            $this->assertRestrictions($stay, $lines);

            $token = bin2hex(random_bytes(32));
            $minutes = $this->config->businessRules()->holds->webMinutes;
            $expiresAt = now()->addMinutes($minutes);

            $session = CheckoutSession::query()->create([
                'token_hash' => CheckoutSession::hashToken($token),
                'departure_id' => null,
                'cabins' => [],
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
                'rooms' => [],
                'guest' => $guest,
                'status' => CheckoutSessionStatus::Holding,
                'expires_at' => $expiresAt,
                'extended' => false,
                'ip_hash' => $ipHash,
            ]);

            $held = [];

            foreach ($lines as $line) {
                $type = $this->type($line['room_type']);

                $claimed = $this->claims->claimType(
                    $stay,
                    $type,
                    1,
                    $session,
                    ClaimKind::Hold,
                    HoldType::Web,
                    $expiresAt,
                );

                $roomId = (int) $claimed->first()?->room_id;

                if ($roomId === 0) {
                    throw new RoomUnavailableException($type->code, $stay->checkIn()->toDateString());
                }

                $held[] = [
                    'room_type' => $line['room_type'],
                    'adults' => $line['adults'],
                    'child_ages' => $line['child_ages'],
                    'rate_plan' => $line['rate_plan'],
                    'room_id' => $roomId,
                ];
            }

            $session->rooms = $held;
            $session->save();

            return [
                'session' => $session->refresh(),
                'token' => $token,
                'quote' => $quote,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function stay(array $token): StayDates
    {
        $checkIn = $token['check_in'] ?? null;
        $checkOut = $token['check_out'] ?? null;

        if (! is_string($checkIn) || ! is_string($checkOut)) {
            throw ValidationException::withMessages([
                'quote_token' => ['This quote has expired.'],
            ]);
        }

        return StayDates::of($checkIn, $checkOut);
    }

    /**
     * @param  array<string, mixed>  $token
     * @return list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>
     */
    private function lines(array $token): array
    {
        $rooms = $token['rooms'] ?? null;

        if (! is_array($rooms) || $rooms === []) {
            throw ValidationException::withMessages([
                'quote_token' => ['This quote has expired.'],
            ]);
        }

        $lines = [];

        foreach ($rooms as $room) {
            if (! is_array($room) || ! is_string($room['room_type'] ?? null) || ! is_string($room['rate_plan'] ?? null)) {
                throw ValidationException::withMessages([
                    'quote_token' => ['This quote has expired.'],
                ]);
            }

            $ages = [];

            foreach ($room['child_ages'] ?? [] as $age) {
                $ages[] = (int) $age;
            }

            $lines[] = [
                'room_type' => $room['room_type'],
                'adults' => (int) ($room['adults'] ?? 0),
                'child_ages' => $ages,
                'rate_plan' => $room['rate_plan'],
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{room_type: string, adults: int, child_ages: list<int>, rate_plan: string}>  $lines
     */
    private function assertRestrictions(StayDates $stay, array $lines): void
    {
        $counts = [];

        foreach ($lines as $line) {
            $counts[$line['room_type']] = ($counts[$line['room_type']] ?? 0) + 1;
        }

        foreach ($counts as $code => $count) {
            $book = $this->availability->canBook($this->type($code), $stay, $count);
            $blocking = array_values(array_filter(
                $book->reasons,
                static fn (string $reason): bool => $reason !== 'SOLD_OUT' && $reason !== 'NO_SINGLE_ROOM',
            ));

            if ($blocking !== []) {
                throw ValidationException::withMessages([
                    'rooms' => $blocking,
                ]);
            }
        }
    }

    private function type(string $code): RoomType
    {
        $type = RoomType::query()
            ->where('code', $code)
            ->where('status', RoomTypeStatus::Active)
            ->orderBy('id')
            ->first();

        if (! $type instanceof RoomType) {
            throw ValidationException::withMessages([
                'rooms' => ['No room type '.$code.'.'],
            ]);
        }

        return $type;
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
}
