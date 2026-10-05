<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class PriceCheckRequest extends FormRequest
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
            'document' => ['required', 'array'],
            'stays' => ['sometimes', 'array', 'min:1'],
            'stays.*.id' => ['sometimes', 'string', 'max:32'],
            'stays.*.room_type' => ['required', 'string', 'max:16'],
            'stays.*.check_in' => ['required', 'date_format:Y-m-d'],
            'stays.*.nights' => ['required', 'integer', 'min:1', 'max:366'],
            'stays.*.adults' => ['required', 'integer', 'min:0', 'max:36'],
            'stays.*.children' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'stays.*.child_ages' => ['sometimes', 'array'],
            'stays.*.child_ages.*' => ['integer', 'min:0', 'max:120'],
            'stays.*.rate_plan' => ['required', 'string', 'max:32'],
        ];
    }
}
