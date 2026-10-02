<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\DepartureStatus;
use App\Http\Requests\Rms\Concerns\ValidatesDepartureDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDepartureRequest extends FormRequest
{
    use ValidatesDepartureDate;

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
            'date' => ['required', 'date_format:Y-m-d'],
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'itinerary_id' => ['required', 'integer', 'exists:itineraries,id'],
            'status' => ['required', Rule::enum(DepartureStatus::class)],
            'urgency_threshold' => ['sometimes', 'integer', 'min:0', 'max:9'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'public_note' => ['sometimes', 'nullable', 'string', 'max:40'],
            'festive' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $after) => $this->validateSundayAndUniqueness($after));
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('public_note') && $this->input('public_note') === '') {
            $this->merge(['public_note' => null]);
        }
    }
}
