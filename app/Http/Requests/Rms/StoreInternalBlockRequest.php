<?php

declare(strict_types=1);

namespace App\Http\Requests\Rms;

use App\Enums\BlockReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternalBlockRequest extends FormRequest
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
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'reason' => ['required', Rule::enum(BlockReason::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'rooms' => ['required_without:room_type_id', 'prohibits:room_type_id,count', 'array', 'min:1'],
            'rooms.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'room_type_id' => ['required_without:rooms', 'prohibits:rooms', 'integer', 'exists:room_types,id'],
            'count' => ['required_with:room_type_id', 'integer', 'min:1'],
        ];
    }
}
