<?php

declare(strict_types=1);

namespace App\Http\Requests\Engine;

use App\Http\Requests\Engine\Concerns\NormalizesEngineCabins;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CheckPromoRequest extends FormRequest
{
    use NormalizesEngineCabins;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->filled('check_in')) {
            return [
                'code' => ['required', 'string', 'max:64'],
                'check_in' => ['required', 'date_format:Y-m-d'],
                'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
                'room_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            ];
        }

        return [
            'code' => ['required', 'string', 'max:64'],
            'departure_id' => ['required', 'integer', 'exists:departures,id'],
            ...$this->cabinPartyRules(),
            'guests' => ['required', 'array'],
            'guests.adults' => ['required', 'integer', 'min:0', 'max:36'],
            'guests.children' => ['required', 'integer', 'min:0', 'max:36'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->filled('check_in')) {
            return;
        }

        $this->validateAndNormalizeCabins($validator);
    }
}
