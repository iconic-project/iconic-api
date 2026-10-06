<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use Illuminate\Foundation\Http\FormRequest;

class CheckPromoRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'room_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'rate_plan' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
