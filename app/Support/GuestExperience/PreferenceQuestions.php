<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\PreferenceQuestionType;

/**
 * Pre-trip questionnaire (doc 01 §6.2), in the prototype's PREF_Q order.
 * Wording is PENDING the guest-experience team (N6). None are required.
 * Dietary is free text: Kosher is not an option (N6).
 */
final class PreferenceQuestions
{
    /**
     * @return list<PreferenceQuestion>
     */
    public static function all(): array
    {
        return [
            self::text('diet', 'Dietary & food preferences'),
            self::choice('breakfast', 'Breakfast style', ['Continental', 'Full', 'Light', 'Varies by day']),
            self::choice('pillow', 'Pillow preference', ['Soft', 'Medium', 'Firm']),
            self::choice('temp', 'Preferred room temperature', ['Cool (18–20°C)', 'Moderate (21–23°C)', 'Warm (24–26°C)']),
            self::text('bev', 'Preferred beverages'),
            self::choice('intensity', 'Physical activity intensity', ['Low', 'Moderate', 'High']),
            self::choice('time', 'Preferred activity time', ['Early (6–8am)', 'Flexible', 'Later (9–10am)']),
            self::text('interests', 'Main expedition interests'),
            self::text('celebr', 'Special celebrations on board'),
            self::text('access', 'Accessibility requirements', restricted: true),
            self::text('emerg', 'Emergency contact at home', restricted: true),
            self::choice('first', 'First time in Galápagos?', ['Yes', 'No']),
            self::text('req', 'Any special request?'),
        ];
    }

    public static function find(string $key): ?PreferenceQuestion
    {
        foreach (self::all() as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     */
    private static function choice(string $key, string $label, array $options): PreferenceQuestion
    {
        return new PreferenceQuestion($key, $label, PreferenceQuestionType::Choice, $options, false);
    }

    private static function text(string $key, string $label, bool $restricted = false): PreferenceQuestion
    {
        return new PreferenceQuestion($key, $label, PreferenceQuestionType::Text, [], $restricted);
    }
}
