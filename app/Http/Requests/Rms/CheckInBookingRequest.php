<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class CheckInBookingRequest extends FormRequest
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
            'at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
