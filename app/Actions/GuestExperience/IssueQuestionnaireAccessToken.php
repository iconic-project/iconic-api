<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Actions\Action;
use App\Enums\BookingAccessTokenPurpose;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Support\BusinessTime;

final class IssueQuestionnaireAccessToken extends Action
{
    /**
     * @param  list<int>  $coveredGuestIds
     */
    public function handle(Booking $booking, ?int $guestId, array $coveredGuestIds): BookingAccessToken
    {
        /** @var BookingAccessToken $token */
        $token = $this->transaction(function () use ($booking, $guestId, $coveredGuestIds): BookingAccessToken {
            $booking->loadMissing('departure.itinerary');
            $plain = bin2hex(random_bytes(32));
            $pageUrl = rtrim((string) config('iconic.engine_url'), '/').'/questionnaire/'.$plain;

            return BookingAccessToken::query()->create([
                'booking_id' => $booking->id,
                'guest_id' => $guestId,
                'token_hash' => BookingAccessToken::hashToken($plain),
                'purpose' => BookingAccessTokenPurpose::Questionnaire,
                'covered_guest_ids' => $coveredGuestIds,
                'expires_at' => BusinessTime::dayEndUtc($booking->departure->returnDate()->toDateString()),
                'page_url' => $pageUrl,
            ]);
        });

        return $token;
    }
}
