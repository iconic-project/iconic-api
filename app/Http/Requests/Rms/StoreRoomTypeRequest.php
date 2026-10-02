<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Models\Property;
use App\Support\Rooms\RoomTypeOccupancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoomTypeRequest extends FormRequest
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
        $property = $this->route('property');
        $propertyId = $property instanceof Property ? $property->id : 0;

        return [
            'code' => [
                'required',
                'string',
                'max:16',
                Rule::unique('room_types', 'code')->where('property_id', $propertyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'base_occupancy' => ['required', 'integer', 'min:0'],
            'max_occupancy' => ['required', 'integer', 'min:1'],
            'max_adults' => ['required', 'integer', 'min:1'],
            'max_children' => ['required', 'integer', 'min:0'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            ...$this->contentRules(null),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['base_occupancy', 'max_occupancy', 'max_adults', 'max_children'] as $field) {
                if ($validator->errors()->has($field)) {
                    return;
                }
            }

            foreach (RoomTypeOccupancy::errors(
                (int) $this->input('base_occupancy'),
                (int) $this->input('max_occupancy'),
                (int) $this->input('max_adults'),
                (int) $this->input('max_children'),
            ) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('slug') && $this->input('slug') === '') {
            $this->merge(['slug' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function contentRules(?int $ignoreId): array
    {
        return [
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('room_types', 'slug')->ignore($ignoreId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'size_sqm' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bed_setup' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amenities' => ['sometimes', 'array'],
            'amenities.*' => ['required', 'string', 'min:1'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['required', 'array'],
            'photos.*.path' => ['required', 'string', 'max:255'],
            'photos.*.alt' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:155'],
        ];
    }
}
