<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\CheckoutPath;
use App\Models\Booking;
use App\Models\CheckoutSession;
use App\Models\RoomType;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Services\Pricing\StayReservationQuote;
use App\Support\Config\Documents\RatesDocument;
use App\Support\History\History;

final class FallBackOnlineDeposit extends Action
{
    public const HISTORY = 'Online deposit not completed — the online advantage was removed; the request stays open for the team';

    public function __construct(private readonly StayQuoter $quoter) {}

    public function handle(CheckoutSession $session): int
    {
        return $this->transaction(function () use ($session): int {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->path !== CheckoutPath::PayDeposit) {
                return 0;
            }

            $session->load(['bookings.roomType', 'bookings.ratesVersion']);
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

        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $booking->load(['roomType', 'ratesVersion']);

        if ($booking->status !== BookingStatus::Requested || ! $booking->online_deposit) {
            return false;
        }

        $roomType = $booking->roomType;
        $plan = $booking->rate_plan_code;

        if (! $roomType instanceof RoomType || ! is_string($plan) || $plan === '') {
            return false;
        }

        $document = $booking->ratesVersion->asDocument();
        $ages = [];

        foreach ($booking->child_ages ?? [] as $age) {
            $ages[] = (int) $age;
        }

        $result = $this->quoter->quote($roomType, new StayQuoteInput(
            $booking->stay(),
            $roomType->code,
            $booking->adults,
            $ages,
            $plan,
            $booking->promo_code,
            $booking->rates_version_id,
            false,
            $booking->main_channel->segment(),
            $booking->sold_on->toDateString(),
        ), $document instanceof RatesDocument ? $document : null);

        if (! $result instanceof StayReservationQuote) {
            return false;
        }

        $priced = $result->quote->toArray();
        $booking->night_lines = $priced['night_lines'];
        $booking->tax_lines = $priced['tax_lines'];
        $booking->price_lines = $priced['lines'];
        $booking->total = $result->quote->total;
        $booking->deposit_pct = $result->quote->depositPct;
        $booking->balance_days = $result->quote->terms->balanceDays;
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
