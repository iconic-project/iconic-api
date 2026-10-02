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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'property' => ['sometimes', 'string', 'exists:properties,code'],
            'itinerary' => ['sometimes', 'string', 'exists:itineraries,code'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
