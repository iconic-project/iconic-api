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
     *     check_in: string,
     *     check_out: string,
     *     property_name: string,
     *     questions: list<array{key: string, label: string, type: PreferenceQuestionType, options: list<string>, restricted: bool, required: bool}>,
     *     guests: list<array{id: int, first_name: string, room: string, answers: array<string, string>}>
     * }
     */
    public static function forToken(BookingAccessToken $token): array
    {
        $booking = $token->booking;
        $booking->loadMissing(['property', 'room']);
        $stay = $booking->stay();
        $checkIn = $stay->checkIn()->toDateString();
        $propertyName = $booking->property->name;
        $ids = array_map(intval(...), $token->covered_guest_ids ?? []);

        $guests = Guest::query()
            ->where('booking_id', $booking->id)
            ->whereIn('id', $ids)
            ->with(['booking.room', 'currentPreference'])
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
            'check_in' => $checkIn,
            'check_out' => $stay->checkOut()->toDateString(),
            'property_name' => $propertyName,
            'questions' => array_map(
                fn (PreferenceQuestion $question): array => $question->toArray(),
                PreferenceQuestions::all(),
            ),
            'guests' => $guests->map(fn (Guest $guest): array => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'room' => $guest->booking->roomLabel(),
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
