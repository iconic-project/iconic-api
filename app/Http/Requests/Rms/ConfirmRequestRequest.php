<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmRequestRequest extends FormRequest
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
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'override_restrictions' => ['sometimes', 'boolean'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'restriction_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
