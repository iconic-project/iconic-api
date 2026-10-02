<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\SurveyQuestionType;

/**
 * Post-trip survey (doc 01 §6.3), in the prototype's NPS_Q order.
 * Wording is PENDING CLIENT.
 */
final class SurveyQuestions
{
    /**
     * @return list<SurveyQuestion>
     */
    public static function all(): array
    {
        return [
            self::scale('score', 'How would you rate your overall Iconic expedition? (1–10)', 1, 10, required: true),
            self::text('why', 'Please share what influenced your score'),
            self::text('best', 'What was the best part of your expedition?'),
            self::text('better', 'Is there anything Iconic could have done better?'),
            self::text('crew', 'A crew member, guide or team member to highlight'),
            self::scale('rec', 'How likely are you to recommend Iconic? (0–10)', 0, 10, required: false),
        ];
    }

    public static function find(string $key): ?SurveyQuestion
    {
        foreach (self::all() as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, type: SurveyQuestionType, min: int|null, max: int|null}>
     */
    public static function payload(): array
    {
        return array_map(
            fn (SurveyQuestion $question): array => $question->toArray(),
            self::all(),
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $callNotes): array
    {
        $rules = [];

        foreach (self::all() as $question) {
            if ($question->type === SurveyQuestionType::Scale) {
                $rules[$question->key] = [
                    $question->required ? 'required' : 'nullable',
                    'integer',
                    'min:'.(int) $question->min,
                    'max:'.(int) $question->max,
                ];

                continue;
            }

            $rules[$question->key] = ['nullable', 'string', 'max:'.(int) $question->textMax];
        }

        if ($callNotes) {
            $rules['call_notes'] = ['nullable', 'string', 'max:1000'];
            $rules['guest_id'] = ['required', 'integer'];
        }

        return $rules;
    }

    public static function allows(string $key, bool $callNotes): bool
    {
        if (self::find($key) instanceof SurveyQuestion) {
            return true;
        }

        return $callNotes && in_array($key, ['guest_id', 'call_notes'], true);
    }

    private static function scale(string $key, string $label, int $min, int $max, bool $required): SurveyQuestion
    {
        return new SurveyQuestion($key, $label, SurveyQuestionType::Scale, $min, $max, $required, null);
    }

    private static function text(string $key, string $label): SurveyQuestion
    {
        return new SurveyQuestion($key, $label, SurveyQuestionType::Text, null, null, false, 1000);
    }
}
