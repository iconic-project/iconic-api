<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class NpsRules
{
    public function __construct(
        public int $surveyHoursAfterCheckOut,
        public int $alertBelow,
        public int $reviewRequestFrom,
        public string $reviewUrl,
        public bool $usesLegacyKey = false,
    ) {}

    /**
     * A stored document may still name the pre-rename key. Publishing that
     * shape keeps the old key so a rename is a real diff (09 H10).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $hasNew = array_key_exists('survey_hours_after_check_out', $data);
        $hours = $hasNew
            ? (int) $data['survey_hours_after_check_out']
            : (int) ($data['survey_hours_after_return'] ?? 0);

        return new self(
            $hours,
            (int) ($data['alert_below'] ?? 0),
            (int) ($data['review_request_from'] ?? 0),
            is_string($data['review_url'] ?? null) ? $data['review_url'] : '',
            usesLegacyKey: ! $hasNew && array_key_exists('survey_hours_after_return', $data),
        );
    }

    /**
     * @return array{survey_hours_after_check_out?: int, survey_hours_after_return?: int, alert_below: int, review_request_from: int, review_url: string}
     */
    public function toArray(): array
    {
        $hoursKey = $this->usesLegacyKey
            ? 'survey_hours_after_return'
            : 'survey_hours_after_check_out';

        return [
            $hoursKey => $this->surveyHoursAfterCheckOut,
            'alert_below' => $this->alertBelow,
            'review_request_from' => $this->reviewRequestFrom,
            'review_url' => $this->reviewUrl,
        ];
    }
}
