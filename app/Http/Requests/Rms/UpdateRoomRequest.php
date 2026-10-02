<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
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
        $room = $this->route('room');
        $roomId = $room instanceof Room ? $room->id : null;
        $propertyId = $room instanceof Room ? $room->property_id : 0;

        return [
            'code' => [
                'sometimes',
                'string',
                'max:8',
                Rule::unique('rooms', 'code')->where('property_id', $propertyId)->ignore($roomId),
            ],
            'label' => ['sometimes', 'string', 'max:255'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:32'],
            'room_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('room_types', 'id')->where('property_id', $propertyId),
            ],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
