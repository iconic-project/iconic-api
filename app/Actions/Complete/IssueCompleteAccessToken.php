<?php

declare(strict_types=1);

namespace App\Actions\Complete;

use App\Actions\Action;
use App\Enums\BookingAccessTokenPurpose;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Support\BusinessTime;
use Illuminate\Validation\ValidationException;

final class IssueCompleteAccessToken extends Action
{
    public function __construct(private readonly RevokeCompleteAccessTokens $revoke) {}

    public function handle(Booking $booking): string
    {
        return $this->transaction(function () use ($booking): string {
            $booking = Booking::query()->withTrashed()->with('property')->lockForUpdate()->findOrFail($booking->id);
            $this->guardBooking($booking);

            $active = BookingAccessToken::query()
                ->where('booking_id', $booking->id)
                ->where('purpose', BookingAccessTokenPurpose::Complete)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($active instanceof BookingAccessToken) {
                return $active->page_url;
            }

            $this->revoke->handle($booking);

            $token = bin2hex(random_bytes(32));
            $pageUrl = rtrim((string) config('iconic.engine_url'), '/').'/complete/'.$token;
            $checkIn = $booking->stay()->checkIn()->toDateString();

            BookingAccessToken::query()->create([
                'booking_id' => $booking->id,
                'token_hash' => BookingAccessToken::hashToken($token),
                'purpose' => BookingAccessTokenPurpose::Complete,
                'expires_at' => BusinessTime::dayEndUtc($checkIn),
                'page_url' => $pageUrl,
            ]);

            return $pageUrl;
        });
    }

    private function guardBooking(Booking $booking): void
    {
        if ($booking->trashed() || in_array($booking->status, [
            BookingStatus::Cancelled,
            BookingStatus::CancelledPostpaid,
            BookingStatus::Released,
        ], true)) {
            throw ValidationException::withMessages([
                'booking' => ['A complete-reservation link cannot be issued for a cancelled or released booking.'],
            ]);
        }
    }
}
