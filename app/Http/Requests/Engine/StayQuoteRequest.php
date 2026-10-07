<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use Illuminate\Foundation\Http\FormRequest;

class StayQuoteRequest extends FormRequest
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
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_type' => ['required', 'string', 'max:32'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:36'],
            'rooms.*.child_ages' => ['present', 'array'],
            'rooms.*.child_ages.*' => ['integer', 'min:0', 'max:120'],
            'rooms.*.rate_plan' => ['required', 'string', 'max:32'],
            'rooms.*.online_deposit' => ['sometimes', 'boolean'],
        ];
    }
}
