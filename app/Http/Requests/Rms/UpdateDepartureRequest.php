<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\DepartureStatus;
use App\Http\Requests\Rms\Concerns\ValidatesDepartureDate;
use App\Models\Departure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDepartureRequest extends FormRequest
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
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'required', 'integer', 'exists:properties,id'],
            'itinerary_id' => ['sometimes', 'required', 'integer', 'exists:itineraries,id'],
            'status' => ['sometimes', 'required', Rule::enum(DepartureStatus::class)],
            'urgency_threshold' => ['sometimes', 'integer', 'min:0', 'max:9'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'public_note' => ['sometimes', 'nullable', 'string', 'max:40'],
            'festive' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $departure = $this->route('departure');
        $ignoreId = $departure instanceof Departure ? $departure->id : null;

        if (! $this->exists('date') && ! $this->exists('property_id')) {
            return;
        }

        $dateInput = $this->exists('date')
            ? $this->input('date')
            : ($departure instanceof Departure ? $departure->date->toDateString() : null);
        $propertyId = $this->exists('property_id')
            ? $this->input('property_id')
            : ($departure instanceof Departure ? $departure->property_id : null);

        $validator->after(fn (Validator $after) => $this->validateSundayAndUniqueness(
            $after,
            $ignoreId,
            $dateInput,
            $propertyId,
        ));
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('public_note') && $this->input('public_note') === '') {
            $this->merge(['public_note' => null]);
        }
    }
}
