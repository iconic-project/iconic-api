<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends FormRequest
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
        $propertyId = $property instanceof Property ? $property->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('properties', 'slug')->ignore($propertyId),
            ],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'hero_image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hero_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'highlights' => ['sometimes', 'array'],
            'highlights.*' => ['required', 'string', 'min:1'],
            'facts' => ['sometimes', 'array'],
            'facts.*' => ['required', 'array', 'size:2'],
            'facts.*.0' => ['required', 'string', 'min:1'],
            'facts.*.1' => ['required', 'string', 'min:1'],
            'faqs' => ['sometimes', 'array'],
            'faqs.*' => ['required', 'array', 'size:2'],
            'faqs.*.0' => ['required', 'string', 'min:1'],
            'faqs.*.1' => ['required', 'string', 'min:1'],
            'policies_text' => ['sometimes', 'nullable', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:155'],
            'status' => ['sometimes', 'required', Rule::enum(PropertyStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('slug') && $this->input('slug') === '') {
            $this->merge(['slug' => null]);
        }
    }
}
