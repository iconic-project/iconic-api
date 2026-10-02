<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Models\RoomType;
use App\Support\Rooms\RoomTypeOccupancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoomTypeRequest extends FormRequest
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
        $type = $this->route('room_type');
        $typeId = $type instanceof RoomType ? $type->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'base_occupancy' => ['sometimes', 'integer', 'min:0'],
            'max_occupancy' => ['sometimes', 'integer', 'min:1'],
            'max_adults' => ['sometimes', 'integer', 'min:1'],
            'max_children' => ['sometimes', 'integer', 'min:0'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('room_types', 'slug')->ignore($typeId),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->route('roomType');

            if (! $type instanceof RoomType) {
                return;
            }

            foreach (['base_occupancy', 'max_occupancy', 'max_adults', 'max_children'] as $field) {
                if ($validator->errors()->has($field)) {
                    return;
                }
            }

            foreach (RoomTypeOccupancy::errors(
                (int) $this->input('base_occupancy', $type->base_occupancy),
                (int) $this->input('max_occupancy', $type->max_occupancy),
                (int) $this->input('max_adults', $type->max_adults),
                (int) $this->input('max_children', $type->max_children),
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
}
