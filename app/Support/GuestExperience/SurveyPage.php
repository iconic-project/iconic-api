<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\SurveyQuestionType;
use App\Models\BookingAccessToken;
use App\Models\Guest;
use App\Models\GuestResponse;

final class SurveyPage
{
    /**
     * @return array{
     *     reference: string,
     *     check_in: string,
     *     property_name: string,
     *     questions: list<array{key: string, label: string, type: SurveyQuestionType, min: int|null, max: int|null}>,
     *     guests: list<array{id: int, first_name: string, last_name: string, responded: bool}>
     * }
     */
    public static function forToken(BookingAccessToken $token): array
    {
        $booking = $token->booking;
        $booking->loadMissing('property');
        $ids = array_map(intval(...), $token->covered_guest_ids ?? []);

        $responded = GuestResponse::query()
            ->where('booking_id', $booking->id)
            ->whereIn('guest_id', $ids === [] ? [0] : $ids)
            ->pluck('guest_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $guests = Guest::query()
            ->where('booking_id', $booking->id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->sortBy(function (Guest $guest) use ($ids): int {
                $position = array_search($guest->id, $ids, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->values();

        return [
            'reference' => (string) ($booking->displayReference() ?? ''),
            'check_in' => $booking->stay()->checkIn()->toDateString(),
            'property_name' => $booking->property->name,
            'questions' => SurveyQuestions::payload(),
            'guests' => $guests->map(fn (Guest $guest): array => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'responded' => in_array($guest->id, $responded, true),
            ])->all(),
        ];
    }
}
