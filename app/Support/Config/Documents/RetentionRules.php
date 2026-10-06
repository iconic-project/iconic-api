<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

final readonly class RetentionRules
{
    public function __construct(
        public int $passportMonthsAfterCheckOut,
        public int $medicalDaysAfterCheckOut,
        public int $behaviouralRawMonths,
        public int $behaviouralUnstitchedDays,
    ) {}

    /**
     * A stored document may still name the pre-rename keys. They are ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['passport_months_after_check_out'] ?? 0),
            (int) ($data['medical_days_after_check_out'] ?? 0),
            (int) ($data['behavioural_raw_months'] ?? 0),
            (int) ($data['behavioural_unstitched_days'] ?? 0),
        );
    }

    /**
     * @return array{
     *     passport_months_after_check_out: int,
     *     medical_days_after_check_out: int,
     *     behavioural_raw_months: int,
     *     behavioural_unstitched_days: int
     * }
     */
    public function toArray(): array
    {
        return [
            'passport_months_after_check_out' => $this->passportMonthsAfterCheckOut,
            'medical_days_after_check_out' => $this->medicalDaysAfterCheckOut,
            'behavioural_raw_months' => $this->behaviouralRawMonths,
            'behavioural_unstitched_days' => $this->behaviouralUnstitchedDays,
        ];
    }
}
