<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\RegistrationField;

final readonly class RegistrationRules
{
    /**
     * HQ9 safe default: name, nationality, DOB, document no., arrival, departure.
     * No hour count is named, so the deadline stays null.
     *
     * @return array{fields: list<string>, formats: list<string>, deadline_hours_after_check_in: null}
     */
    public static function defaults(): array
    {
        return [
            'fields' => [
                RegistrationField::FullName->value,
                RegistrationField::Nationality->value,
                RegistrationField::Dob->value,
                RegistrationField::DocumentNumber->value,
                RegistrationField::CheckIn->value,
                RegistrationField::CheckOut->value,
            ],
            'formats' => ['CSV', 'PDF'],
            'deadline_hours_after_check_in' => null,
        ];
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $formats
     */
    public function __construct(
        public array $fields,
        public array $formats,
        public ?int $deadlineHoursAfterCheckIn,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = self::fields($data['fields'] ?? null, ! array_key_exists('fields', $data), []);
        $formats = self::formats($data['formats'] ?? null, ! array_key_exists('formats', $data), []);
        $deadline = $data['deadline_hours_after_check_in'] ?? null;

        return new self(
            $fields,
            $formats,
            $deadline === null || $deadline === '' ? null : (int) $deadline,
        );
    }

    /**
     * @return array{fields: list<string>, formats: list<string>, deadline_hours_after_check_in: int|null}
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'formats' => $this->formats,
            'deadline_hours_after_check_in' => $this->deadlineHoursAfterCheckIn,
        ];
    }

    public function allowsFormat(string $format): bool
    {
        return in_array(strtoupper($format), $this->formats, true);
    }

    /**
     * @return list<RegistrationField>
     */
    public function fieldEnums(): array
    {
        $fields = [];

        foreach ($this->fields as $code) {
            $field = RegistrationField::tryFrom($code);

            if ($field instanceof RegistrationField) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function fields(mixed $raw, bool $missing, array $fallback): array
    {
        if ($missing || ! is_array($raw)) {
            return $fallback;
        }

        $fields = [];

        foreach ($raw as $code) {
            if (is_string($code) && RegistrationField::tryFrom($code) instanceof RegistrationField) {
                $fields[] = $code;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function formats(mixed $raw, bool $missing, array $fallback): array
    {
        if ($missing || ! is_array($raw)) {
            return $fallback;
        }

        $formats = [];

        foreach ($raw as $format) {
            if (! is_string($format)) {
                continue;
            }

            $upper = strtoupper($format);

            if (in_array($upper, ['CSV', 'PDF'], true)) {
                $formats[] = $upper;
            }
        }

        return array_values(array_unique($formats));
    }
}
