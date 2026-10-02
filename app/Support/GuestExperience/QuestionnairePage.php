<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\PreferenceQuestionType;
use App\Models\BookingAccessToken;
use App\Models\Guest;
use App\Models\GuestPreference;

final class QuestionnairePage
{
    /**
     * @return array{
     *     reference: string,
     *     departure_date: string,
     *     itinerary_name: string,
     *     questions: list<array{key: string, label: string, type: PreferenceQuestionType, options: list<string>, restricted: bool, required: bool}>,
     *     guests: list<array{id: int, first_name: string, cabin: string, answers: array<string, string>}>
     * }
     */
    public static function forToken(BookingAccessToken $token): array
    {
        $booking = $token->booking;
        $booking->loadMissing(['departure.itinerary', 'departure.property']);
        $ids = array_map(intval(...), $token->covered_guest_ids ?? []);

        $guests = Guest::query()
            ->where('booking_id', $booking->id)
            ->whereIn('id', $ids)
            ->with(['booking.cabin', 'currentPreference'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->sortBy(function (Guest $guest) use ($ids): int {
                $position = array_search($guest->id, $ids, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->values();

        return [
            'reference' => (string) ($booking->reference ?? ''),
            'departure_date' => $booking->departure->date->toDateString(),
            'itinerary_name' => $booking->departure->itinerary->name,
            'questions' => array_map(
                fn (PreferenceQuestion $question): array => $question->toArray(),
                PreferenceQuestions::all(),
            ),
            'guests' => $guests->map(fn (Guest $guest): array => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'cabin' => GuestCabin::label($guest),
                'answers' => self::answers($guest->currentPreference),
            ])->all(),
        ];
    }

    /**
     * Restricted answers are "provided" or empty. The stored text never leaves the API.
     *
     * @return array<string, string>
     */
    public static function answers(?GuestPreference $preference): array
    {
        $answers = [];

        foreach (PreferenceQuestions::all() as $question) {
            if ($question->restricted) {
                $value = $question->key === 'access'
                    ? $preference?->accessibility
                    : $preference?->emergency_contact;
                $answers[$question->key] = trim((string) $value) === '' ? '' : 'provided';

                continue;
            }

            $answers[$question->key] = $preference?->answer($question->key) ?? '';
        }

        return $answers;
    }
}
