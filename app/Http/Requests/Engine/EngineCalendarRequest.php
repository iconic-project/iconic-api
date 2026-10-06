<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use Illuminate\Foundation\Http\FormRequest;

class EngineCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m'],
            'months' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'adults' => ['required', 'integer', 'min:1', 'max:36'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:36'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('months')) {
            $this->merge(['months' => 1]);
        }
    }
}
