<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StorePortalRequestRequest extends FormRequest
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
            'rooms.*.adults' => ['required', 'integer', 'min:1'],
            'rooms.*.child_ages' => ['sometimes', 'array'],
            'rooms.*.child_ages.*' => ['integer', 'min:0', 'max:120'],
            'rooms.*.rate_plan' => ['sometimes', 'nullable', 'string', 'max:32'],
            'client' => ['required', 'array'],
            'client.name' => ['required', 'string', 'max:255'],
            'client.email' => ['required', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_of_record' => ['accepted'],
            'price' => ['prohibited'],
            'discount' => ['prohibited'],
            'commission_pct' => ['prohibited'],
            'promo_code' => ['prohibited'],
            'agency_id' => ['prohibited'],
        ];
    }
}
