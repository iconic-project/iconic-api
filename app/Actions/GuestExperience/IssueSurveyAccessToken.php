<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Actions\Action;
use App\Enums\BookingAccessTokenPurpose;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Support\BusinessTime;

final class IssueSurveyAccessToken extends Action
{
    /**
     * The survey link expires 60 days after the return date (task 05). Not a business rule.
     */
    private const EXPIRES_DAYS_AFTER_RETURN = 60;

    /**
     * @param  list<int>  $coveredGuestIds
     */
    public function handle(Booking $booking, ?int $guestId, array $coveredGuestIds): BookingAccessToken
    {
        /** @var BookingAccessToken $token */
        $token = $this->transaction(function () use ($booking, $guestId, $coveredGuestIds): BookingAccessToken {
            $booking->loadMissing('departure.itinerary');
            $plain = bin2hex(random_bytes(32));
            $pageUrl = rtrim((string) config('iconic.engine_url'), '/').'/survey/'.$plain;
            $returnDate = $booking->departure->returnDate()->toDateString();
            $expiresOn = BusinessTime::calendarDay($returnDate)->addDays(self::EXPIRES_DAYS_AFTER_RETURN)->toDateString();

            return BookingAccessToken::query()->create([
                'booking_id' => $booking->id,
                'guest_id' => $guestId,
                'token_hash' => BookingAccessToken::hashToken($plain),
                'purpose' => BookingAccessTokenPurpose::Survey,
                'covered_guest_ids' => $coveredGuestIds,
                'expires_at' => BusinessTime::dayEndUtc($expiresOn),
                'page_url' => $pageUrl,
            ]);
        });

        return $token;
    }
}
