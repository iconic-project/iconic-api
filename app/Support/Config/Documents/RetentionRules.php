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
        public bool $usesLegacyPassportKey = false,
        public bool $usesLegacyMedicalKey = false,
    ) {}

    /**
     * A stored document may still name the pre-rename keys. Publishing that
     * shape keeps those keys so a rename is a real diff (09 H10).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $hasPassport = array_key_exists('passport_months_after_check_out', $data);
        $hasMedical = array_key_exists('medical_days_after_check_out', $data);

        return new self(
            $hasPassport
                ? (int) $data['passport_months_after_check_out']
                : (int) ($data['passport_months_after_cruise'] ?? 0),
            $hasMedical
                ? (int) $data['medical_days_after_check_out']
                : (int) ($data['medical_days_after_cruise'] ?? 0),
            (int) ($data['behavioural_raw_months'] ?? 0),
            (int) ($data['behavioural_unstitched_days'] ?? 0),
            usesLegacyPassportKey: ! $hasPassport && array_key_exists('passport_months_after_cruise', $data),
            usesLegacyMedicalKey: ! $hasMedical && array_key_exists('medical_days_after_cruise', $data),
        );
    }

    /**
     * @return array{
     *     passport_months_after_check_out?: int,
     *     passport_months_after_cruise?: int,
     *     medical_days_after_check_out?: int,
     *     medical_days_after_cruise?: int,
     *     behavioural_raw_months: int,
     *     behavioural_unstitched_days: int
     * }
     */
    public function toArray(): array
    {
        $passportKey = $this->usesLegacyPassportKey
            ? 'passport_months_after_cruise'
            : 'passport_months_after_check_out';
        $medicalKey = $this->usesLegacyMedicalKey
            ? 'medical_days_after_cruise'
            : 'medical_days_after_check_out';

        return [
            $passportKey => $this->passportMonthsAfterCheckOut,
            $medicalKey => $this->medicalDaysAfterCheckOut,
            'behavioural_raw_months' => $this->behaviouralRawMonths,
            'behavioural_unstitched_days' => $this->behaviouralUnstitchedDays,
        ];
    }
}
