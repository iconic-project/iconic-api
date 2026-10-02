<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInternalBlocksRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(['active', 'released', 'all'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'integer', 'exists:properties,id'],
        ];
    }
}
