<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

final class EngineSettingsConstraint implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function __construct(private readonly string $check) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $data = $this->validator?->getData() ?? [];

        match ($this->check) {
            'property_fits_cabins' => $this->propertyFitsCabins($value, $data, $fail),
            'child_ages_ordered' => $this->childAgesOrdered($value, $data, $fail),
            'search_range_ordered' => $this->searchRangeOrdered($value, $data, $fail),
            'default_adults_capacity' => $this->defaultAdultsCapacity($value, $data, $fail),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function propertyFitsCabins(mixed $value, array $data, Closure $fail): void
    {
        $cabin = data_get($data, 'guests.max_per_cabin');

        if (! is_numeric($value) || ! is_numeric($cabin)) {
            return;
        }

        $property = (int) $value;
        $perCabin = (int) $cabin;

        if ($property > 9 * $perCabin) {
            $fail('Max per property ('.$property.') is more than 9 cabins × '.$perCabin.' can hold.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function childAgesOrdered(mixed $value, array $data, Closure $fail): void
    {
        $min = data_get($data, 'guests.child_min_age');

        if (! is_numeric($value) || ! is_numeric($min)) {
            return;
        }

        if ((int) $min > (int) $value) {
            $fail('Child age "from" is higher than "to".');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function searchRangeOrdered(mixed $value, array $data, Closure $fail): void
    {
        $from = data_get($data, 'calendar.default_search_from');

        if (! is_string($from) || ! is_string($value) || $from === '' || $value === '') {
            return;
        }

        if ($from > $value) {
            $fail('Default search ends before it starts.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function defaultAdultsCapacity(mixed $value, array $data, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $adults = (int) $value;
        $property = data_get($data, 'guests.max_per_property');
        $cabin = data_get($data, 'guests.max_per_cabin');

        if (is_numeric($property) && $adults > (int) $property) {
            $fail('Default adults must be between 1 and max guests per property.');
        }

        if (is_numeric($cabin) && $adults > (int) $cabin * 9) {
            $fail('Default adults must be at most max guests per cabin × 9.');
        }
    }
}
