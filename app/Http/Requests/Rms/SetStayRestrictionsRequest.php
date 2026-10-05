<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * from and to are inclusive calendar dates. An empty room_type_ids list is
 * property-wide (one row, room_type_id null), not one row per type.
 * A missing restriction field is left as stored. Null min, max, or note clears it.
 */
class SetStayRestrictionsRequest extends FormRequest
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
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'room_type_ids' => ['present', 'array'],
            'room_type_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('room_types', 'id')->where(
                    fn ($query) => $query->where('property_id', $this->input('property_id')),
                ),
            ],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weekdays' => ['sometimes', 'array'],
            'weekdays.*' => ['integer', 'distinct', 'between:1,7'],
            'stop_sell' => ['sometimes', 'boolean'],
            'closed_to_arrival' => ['sometimes', 'boolean'],
            'closed_to_departure' => ['sometimes', 'boolean'],
            'min_stay' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'max_stay' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['stop_sell', 'closed_to_arrival', 'closed_to_departure', 'min_stay', 'max_stay', 'note'] as $key) {
                if ($this->exists($key)) {
                    return;
                }
            }

            $validator->errors()->add('stop_sell', 'Set at least one restriction.');
        });
    }
}
