<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

final class KnownSeason implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $codes = [];

        foreach ($this->seasons() as $season) {
            if (is_array($season) && is_string($season['code'] ?? null)) {
                $codes[] = $season['code'];
            }
        }

        if (! in_array($value, $codes, true)) {
            $fail('Season '.$value.' is not defined.');
        }
    }

    /**
     * @return list<mixed>
     */
    private function seasons(): array
    {
        $data = $this->validator?->getData() ?? [];
        $document = is_array($data['document'] ?? null) && ! array_key_exists('currency', $data)
            ? $data['document']
            : $data;
        $seasons = is_array($document['seasons'] ?? null) ? $document['seasons'] : [];

        return array_values($seasons);
    }
}
