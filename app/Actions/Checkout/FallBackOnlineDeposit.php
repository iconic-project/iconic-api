<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CheckoutPath;
use App\Models\Booking;
use App\Models\CheckoutSession;
use App\Services\Pricing\QuotedParty;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Config\Documents\RatesDocument;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;

final class FallBackOnlineDeposit extends Action
{
    public const HISTORY = 'Online deposit not completed — the online advantage was removed; the request stays open for the team';

    public function __construct(private readonly ReservationQuoter $quoter) {}

    public function handle(CheckoutSession $session): int
    {
        return $this->transaction(function () use ($session): int {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->path !== CheckoutPath::PayDeposit) {
                return 0;
            }

            $session->load(['bookings.departure.property.cabins', 'bookings.ratesVersion']);
            $changed = 0;

            foreach ($session->bookings as $booking) {
                if ($this->removeAdvantage($booking)) {
                    $changed++;
                }
            }

            return $changed;
        });
    }

    private function removeAdvantage(Booking $booking): bool
    {
        if ($booking->status !== BookingStatus::Requested || ! $booking->online_deposit) {
            return false;
        }

        DepartureLocks::lock((int) $booking->departure_id);
        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $booking->load(['departure.property.cabins', 'ratesVersion', 'cabin']);

        if ($booking->status !== BookingStatus::Requested || ! $booking->online_deposit) {
            return false;
        }

        $document = $booking->ratesVersion->asDocument();

        $quote = $this->quoter->quote([
            'departure_id' => $booking->departure_id,
            'type' => BookingType::Cabin->value,
            'cabins' => [[
                'cabin_code' => $booking->cabin?->code,
                'adults' => $booking->adults,
                'children' => $booking->children,
            ]],
            'online_deposit' => false,
            'promo_code' => $booking->promo_code,
            'booking_date' => $booking->sold_on->toDateString(),
            'rates' => $document instanceof RatesDocument ? $document : null,
        ], $booking->departure);

        $party = $quote->parties[0] ?? null;
        $priced = $party instanceof QuotedParty ? $party->quote : null;

        if ($priced === null) {
            return false;
        }

        $booking->price_lines = $priced->toArray()['lines'];
        $booking->total = $priced->total;
        $booking->online_deposit = false;
        $booking->save();

        History::record($booking, 'booking.updated', after: [
            'what' => self::HISTORY,
            'online_deposit' => false,
            'total' => $booking->total,
        ], system: true);

        return true;
    }
}
