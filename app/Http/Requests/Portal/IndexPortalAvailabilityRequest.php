<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class IndexPortalAvailabilityRequest extends FormRequest
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
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:36'],
            'child_ages' => ['sometimes', 'array'],
            'child_ages.*' => ['integer', 'min:0', 'max:120'],
            'rooms' => ['sometimes', 'integer', 'min:1', 'max:36'],
        ];
    }
}
