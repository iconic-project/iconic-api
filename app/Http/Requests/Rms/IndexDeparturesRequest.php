<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\DepartureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDeparturesRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'integer', 'exists:properties,id'],
            'status' => ['sometimes', Rule::enum(DepartureStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'with_cabins' => ['sometimes', 'boolean'],
        ];
    }
}
