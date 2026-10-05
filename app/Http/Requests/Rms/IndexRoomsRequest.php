<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;

class IndexRoomsRequest extends FormRequest
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
            'free_from' => ['sometimes', 'date_format:Y-m-d'],
            'free_to' => ['sometimes', 'date_format:Y-m-d', 'after:free_from'],
        ];
    }
}
