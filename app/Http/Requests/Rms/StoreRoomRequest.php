<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
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
                'max:8',
                Rule::unique('rooms', 'code')->where('property_id', $propertyId),
            ],
            'label' => ['required', 'string', 'max:255'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:32'],
            'room_type_id' => [
                'required',
                'integer',
                Rule::exists('room_types', 'id')->where('property_id', $propertyId),
            ],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
