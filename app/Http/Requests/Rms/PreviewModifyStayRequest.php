<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class PreviewModifyStayRequest extends FormRequest
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
            'room_type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'reason_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'reprice' => ['sometimes', 'boolean'],
        ];
    }
}
